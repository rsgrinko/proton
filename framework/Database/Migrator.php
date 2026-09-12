<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Database;

use Rsgrinko\Proton\Database\Schema\Blueprint;
use Rsgrinko\Proton\Database\Schema\Builder;
use Throwable;

/**
 * Миграции. Каждая — свой файл в каталоге migrations/:
 * `20260912093000_create_users.php` с классом `CreateUsers` в App\Migrations.
 * Файлы подхватываются сами, реестр править не нужно, порядок — по имени файла,
 * то есть по времени создания.
 *
 * Применённые запоминаются в таблице migrations вместе с номером пачки: накат
 * кладёт всё сделанное за раз в одну пачку, откат снимает последнюю пачку целиком.
 */
final class Migrator
{
    /** Имя общей блокировки и сколько ждать её освобождения */
    private const LOCK_NAME    = 'migrations';
    private const LOCK_TIMEOUT = 60;

    private Connection $db;

    private string $path;

    private ?Builder $builder = null;

    /** @var array<string, Migration>|null */
    private ?array $migrations = null;

    public function __construct(?Connection $db = null, ?string $path = null)
    {
        $this->db   = $db ?? Connection::instance();
        $this->path = $path ?? APP_ROOT . '/migrations';
    }

    /**
     * Применяет все новые миграции, возвращает список применённых имён.
     *
     * @return array<int, string>
     */
    public function run(): array
    {
        return $this->locked(function (): array {
            $this->prepare();

            $pending = $this->pending();

            if ($pending === []) {
                return [];
            }

            $batch   = $this->nextBatch();
            $applied = [];

            foreach ($pending as $name) {
                $migration = $this->migrations()[$name];

                $this->guard($name, function () use ($migration, $name, $batch): void {
                    // В SQLite DDL транзакционен, и упавшая миграция откатится целиком.
                    // MySQL на каждом ALTER делает неявный коммит — там транзакция не
                    // спасёт, зато записи о наполовину применённой миграции не появится,
                    // и следующий накат повторит её шаги, пропустив уже сделанные
                    $this->db->transaction(function () use ($migration, $name, $batch): void {
                        $migration->up();

                        $this->db->insert('migrations', [
                            'name'       => $name,
                            'batch'      => $batch,
                            'applied_at' => Connection::now(),
                        ]);
                    });
                });

                $applied[] = $name;
            }

            return $applied;
        });
    }

    /**
     * Откатывает последние пачки, возвращает откаченные имена в порядке отката.
     *
     * @return array<int, string>
     */
    public function rollback(int $batches = 1): array
    {
        if (!$this->db->hasTable('migrations')) {
            return [];
        }

        return $this->locked(function () use ($batches): array {
            $this->prepare();

            $names = $this->lastBatches(max(1, $batches));

            if ($names === []) {
                return [];
            }

            $known      = $this->migrations();
            $rolledBack = [];

            foreach ($names as $name) {
                if (!isset($known[$name])) {
                    throw new DatabaseException(
                        'Миграция ' . $name . ' есть в базе, но не в коде — откатить её нечем.'
                    );
                }

                $migration = $known[$name];

                $this->guard($name, function () use ($migration, $name): void {
                    $this->db->transaction(function () use ($migration, $name): void {
                        $migration->down();

                        $this->db->execute('DELETE FROM migrations WHERE name = :name', ['name' => $name]);
                    });
                });

                $rolledBack[] = $name;
            }

            return $rolledBack;
        });
    }

    /**
     * Что сделают новые миграции, если их применить: имя -> список запросов.
     * Ничего не выполняет — это `migrate --pretend`.
     *
     * @return array<string, array<int, string>>
     */
    public function pretend(): array
    {
        $plan = [];

        foreach ($this->pending() as $name) {
            $builder = new Builder($this->db, true);
            $class   = get_class($this->migrations()[$name]);

            /** @var Migration $migration */
            $migration = new $class($builder);

            $migration->up();

            $plan[$name] = $builder->log();
        }

        return $plan;
    }

    /**
     * Ещё не применённые миграции.
     *
     * @return array<int, string>
     */
    public function pending(): array
    {
        return array_values(array_diff(array_keys($this->migrations()), $this->applied()));
    }

    /**
     * Применённые миграции в порядке применения.
     *
     * @return array<int, string>
     */
    public function applied(): array
    {
        if (!$this->db->hasTable('migrations')) {
            return [];
        }

        return array_map(
            static fn (array $row): string => (string) $row['name'],
            $this->db->select('SELECT name FROM migrations ORDER BY name')
        );
    }

    /**
     * Шаги последнего наката, пропущенные как уже выполненные: так выглядит докат
     * миграции, упавшей на середине.
     *
     * @return array<int, string>
     */
    public function skipped(): array
    {
        return $this->builder === null ? [] : $this->builder->skipped();
    }

    /**
     * Миграции, которые есть в базе, но которых нет в коде: обычно это откат
     * релиза. Молча игнорировать такое нельзя.
     *
     * @return array<int, string>
     */
    public function unknown(): array
    {
        return array_values(array_diff($this->applied(), array_keys($this->migrations())));
    }

    /**
     * Все миграции: имя файла без расширения -> объект.
     *
     * @return array<string, Migration>
     */
    public function migrations(): array
    {
        if ($this->migrations !== null) {
            return $this->migrations;
        }

        $builder = $this->builder = new Builder($this->db);
        $found   = [];

        foreach ((array) glob($this->path . '/*.php') as $file) {
            $file = (string) $file;
            $name = basename($file, '.php');

            if (preg_match('/^(\d{14})_([a-z0-9_]+)$/', $name, $matches) !== 1) {
                throw new DatabaseException(
                    'Файл миграции ' . $name . ' назван не по правилу 20260912093000_имя_миграции.php'
                );
            }

            $class = $this->className($matches[2]);

            require_once $file;

            if (!class_exists($class) || !is_subclass_of($class, Migration::class)) {
                throw new DatabaseException(
                    'В файле миграции ' . $name . ' нет класса ' . $class . ', унаследованного от Migration.'
                );
            }

            $found[$name] = new $class($builder);
        }

        ksort($found);

        return $this->migrations = $found;
    }

    /**
     * Класс миграции по хвосту имени файла: create_users -> CreateUsers.
     */
    private function className(string $slug): string
    {
        return 'App\\Migrations\\' . str_replace(' ', '', ucwords(str_replace('_', ' ', $slug)));
    }

    /**
     * Выполняет работу под общей блокировкой: два наката разом — это дважды
     * применённая миграция, а на бою обычно ещё и упавший деплой.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    private function locked(callable $work): mixed
    {
        $lock = new Lock($this->db, self::LOCK_NAME);

        if (!$lock->acquire(self::LOCK_TIMEOUT)) {
            throw new DatabaseException('Миграции уже накатывает другой процесс. Дождитесь его и повторите.');
        }

        try {
            return $work();
        } finally {
            $lock->release();
        }
    }

    /**
     * Выполняет шаг, дополняя любую ошибку именем миграции: без этого на бою
     * приходится гадать, на чём именно встал накат.
     */
    private function guard(string $name, callable $step): void
    {
        try {
            $step();
        } catch (Throwable $e) {
            throw new DatabaseException('Миграция ' . $name . ' не выполнена: ' . $e->getMessage(), [], 0, $e);
        }
    }

    /**
     * Имена миграций последних пачек в порядке отката.
     *
     * @return array<int, string>
     */
    private function lastBatches(int $batches): array
    {
        $rows = $this->db->select('SELECT DISTINCT batch FROM migrations ORDER BY batch DESC LIMIT ' . $batches);

        if ($rows === []) {
            return [];
        }

        $lowest = (int) $rows[count($rows) - 1]['batch'];

        return array_map(
            static fn (array $row): string => (string) $row['name'],
            $this->db->select('SELECT name FROM migrations WHERE batch >= :batch ORDER BY name DESC', ['batch' => $lowest])
        );
    }

    private function nextBatch(): int
    {
        return (int) $this->db->value('SELECT MAX(batch) FROM migrations') + 1;
    }

    /**
     * Служебная таблица: создать, если её нет.
     */
    private function prepare(): void
    {
        if ($this->db->hasTable('migrations')) {
            return;
        }

        (new Builder($this->db))->create('migrations', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->integer('batch')->default(0);
            $table->dateTime('applied_at');
        });
    }
}
