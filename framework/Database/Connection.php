<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Database;

use PDO;
use PDOException;
use PDOStatement;
use Rsgrinko\Proton\Database\Query\Builder;
use Rsgrinko\Proton\Support\Config;
use Rsgrinko\Proton\Support\Profiler;
use Throwable;

/**
 * Подключение к базе: тонкая обёртка над PDO. Знает два драйвера — sqlite и mysql —
 * и прячет различия между ними (автоинкремент, upsert, блокировки строк).
 *
 * Долгоживущий процесс (воркер) держит соединение часами, поэтому обрыв связи
 * ловится и соединение поднимается заново — см. run().
 */
final class Connection
{
    public const SQLITE = 'sqlite';
    public const MYSQL  = 'mysql';

    private static ?self $instance = null;

    private PDO $pdo;

    private string $driver;

    /**
     * Настройки, с которыми открыли это подключение.
     *
     * Держим их у себя, а не подсматриваем в конфиге: подключений в процессе бывает
     * несколько (тесты работают на своей базе), и «взять настройки из конфига»
     * означало бы переподключиться не туда, откуда выпали.
     *
     * @var array<string, mixed>
     */
    private array $config;

    /**
     * @param array<string, mixed>|null $config блок 'db' конфигурации
     */
    public function __construct(?array $config = null)
    {
        $config = $config ?? (array) Config::get('db', []);
        $driver = (string) ($config['driver'] ?? self::SQLITE);

        if (!in_array($driver, [self::SQLITE, self::MYSQL], true)) {
            throw new DatabaseException('Неизвестный драйвер базы данных: ' . $driver);
        }

        $this->config = $config;
        $this->driver = $driver;
        $this->pdo    = $driver === self::SQLITE
            ? $this->connectSqlite((array) ($config['sqlite'] ?? []))
            : $this->connectMysql((array) ($config['mysql'] ?? []));
    }

    /**
     * Общее подключение на весь процесс.
     */
    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Подменить подключение — нужно тестам и командам, работающим со второй базой.
     */
    public static function setInstance(?self $connection): void
    {
        self::$instance = $connection;
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function isSqlite(): bool
    {
        return $this->driver === self::SQLITE;
    }

    /**
     * Настройки MySQL — с ними работает mysqldump в резервных копиях.
     *
     * @return array<string, mixed>
     */
    public function mysqlConfig(): array
    {
        return (array) ($this->config['mysql'] ?? []);
    }

    /**
     * Путь к файлу базы SQLite или пустая строка.
     */
    public function sqlitePath(): string
    {
        return (string) ($this->config['sqlite']['path'] ?? '');
    }

    /**
     * Построитель запросов по таблице: $db->table('users')->where(...)->get().
     */
    public function table(string $table): Builder
    {
        return new Builder($this, $table);
    }

    /**
     * Все строки запроса.
     *
     * @param array<string|int, mixed> $params
     *
     * @return array<int, array<string, mixed>>
     */
    public function select(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    /**
     * Первая строка или null.
     *
     * @param array<string|int, mixed> $params
     *
     * @return array<string, mixed>|null
     */
    public function selectOne(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Одно значение из первой строки.
     *
     * @param array<string|int, mixed> $params
     */
    public function value(string $sql, array $params = []): mixed
    {
        $row = $this->selectOne($sql, $params);

        if ($row === null) {
            return null;
        }

        return reset($row);
    }

    /**
     * Запрос без выборки. Возвращает число затронутых строк.
     *
     * @param array<string|int, mixed> $params
     */
    public function execute(string $sql, array $params = []): int
    {
        return $this->run($sql, $params)->rowCount();
    }

    /**
     * Вставка строки, возвращает id новой записи.
     *
     * @param array<string, mixed> $data
     */
    public function insert(string $table, array $data): int
    {
        $columns      = array_keys($data);
        $placeholders = array_map(static fn (string $column): string => ':' . $column, $columns);

        $this->run(
            sprintf('INSERT INTO %s (%s) VALUES (%s)', $table, implode(', ', $columns), implode(', ', $placeholders)),
            $data
        );

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Обновление по условию вида ['id' => 5].
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where
     */
    public function update(string $table, array $data, array $where): int
    {
        $set        = [];
        $parameters = [];

        foreach ($data as $column => $value) {
            $set[]                        = $column . ' = :set_' . $column;
            $parameters['set_' . $column] = $value;
        }

        $conditions = [];

        foreach ($where as $column => $value) {
            $conditions[]                   = $column . ' = :where_' . $column;
            $parameters['where_' . $column] = $value;
        }

        return $this->execute(
            sprintf('UPDATE %s SET %s WHERE %s', $table, implode(', ', $set), implode(' AND ', $conditions)),
            $parameters
        );
    }

    /**
     * Удаление по условию.
     *
     * @param array<string, mixed> $where
     */
    public function delete(string $table, array $where): int
    {
        $conditions = [];

        foreach (array_keys($where) as $column) {
            $conditions[] = $column . ' = :' . $column;
        }

        return $this->execute(
            sprintf('DELETE FROM %s WHERE %s', $table, implode(' AND ', $conditions)),
            $where
        );
    }

    /**
     * Выполняет замыкание в транзакции: ошибка — всё откатывается.
     * Вложенный вызов работает внутри уже открытой транзакции.
     *
     * @template T
     *
     * @param callable(self): T $callback
     *
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $callback($this);
        }

        $this->pdo->beginTransaction();

        try {
            $result = $callback($this);

            // Коммитим, только если транзакция ещё жива: в MySQL любой ALTER
            // коммитит её сам, и commit() бросил бы «There is no active transaction»
            if ($this->pdo->inTransaction()) {
                $this->pdo->commit();
            }

            return $result;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    /**
     * Страница результатов: считает общее число строк и отдаёт нужный кусок.
     * Запрос передаётся без LIMIT.
     *
     * @param array<string|int, mixed> $params
     *
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, pages: int, per_page: int}
     */
    public function page(string $sql, array $params = [], int $page = 1, int $perPage = 30): array
    {
        $perPage = max(1, min(200, $perPage));
        $total   = (int) $this->value($this->countSql($sql), $params);
        $pages   = max(1, (int) ceil($total / $perPage));
        $page    = max(1, min($page, $pages));

        return [
            'items'    => $this->select($sql . ' LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage), $params),
            'total'    => $total,
            'page'     => $page,
            'pages'    => $pages,
            'per_page' => $perPage,
        ];
    }

    /**
     * Сколько строк в таблице. Нет таблицы — ноль: страница состояния это переживёт.
     */
    public function count(string $table): int
    {
        return $this->hasTable($table) ? (int) $this->value('SELECT COUNT(*) FROM ' . $table) : 0;
    }

    public function hasTable(string $table): bool
    {
        if ($this->isSqlite()) {
            return $this->selectOne(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name = :name",
                ['name' => $table]
            ) !== null;
        }

        return $this->selectOne(
            'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :name',
            ['name' => $table]
        ) !== null;
    }

    /**
     * Есть ли колонка. Нужно миграциям и коду, который может приехать раньше них.
     */
    public function hasColumn(string $table, string $column): bool
    {
        if (!$this->hasTable($table)) {
            return false;
        }

        if ($this->isSqlite()) {
            // Имя таблицы в PRAGMA параметром не подставить — сверяем его сами
            if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
                throw new DatabaseException('Недопустимое имя таблицы: ' . $table);
            }

            foreach ($this->select('PRAGMA table_info(' . $table . ')') as $row) {
                if ((string) $row['name'] === $column) {
                    return true;
                }
            }

            return false;
        }

        return $this->selectOne(
            'SELECT column_name FROM information_schema.columns
                WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column',
            ['table' => $table, 'column' => $column]
        ) !== null;
    }

    public function hasIndex(string $table, string $index): bool
    {
        if (!$this->hasTable($table)) {
            return false;
        }

        if ($this->isSqlite()) {
            return $this->selectOne(
                "SELECT name FROM sqlite_master WHERE type = 'index' AND name = :index AND tbl_name = :table",
                ['index' => $index, 'table' => $table]
            ) !== null;
        }

        return $this->selectOne(
            'SELECT index_name FROM information_schema.STATISTICS
                WHERE table_schema = DATABASE() AND table_name = :table AND index_name = :index
                LIMIT 1',
            ['table' => $table, 'index' => $index]
        ) !== null;
    }

    /**
     * Имена всех таблиц базы.
     *
     * @return array<int, string>
     */
    public function tables(): array
    {
        $rows = $this->isSqlite()
            ? $this->select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")
            : $this->select('SELECT table_name AS name FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY table_name');

        return array_map(static fn (array $row): string => (string) $row['name'], $rows);
    }

    /**
     * Текущее время в том виде, в каком мы храним даты.
     */
    public static function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    /**
     * Время со сдвигом в секундах.
     */
    public static function at(int $secondsFromNow): string
    {
        return date('Y-m-d H:i:s', time() + $secondsFromNow);
    }

    private function connectSqlite(array $config): PDO
    {
        $path = (string) ($config['path'] ?? '');

        if ($path === '') {
            $path = APP_ROOT . '/var/app.sqlite';
        }

        if ($path !== ':memory:') {
            $dir = dirname($path);

            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
        }

        try {
            $pdo = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            throw new DatabaseException('Не удалось открыть базу SQLite: ' . $e->getMessage(), [], 0, $e);
        }

        // WAL даёт нормальную параллельную работу воркера и веб-части
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 10000');

        return $pdo;
    }

    private function connectMysql(array $config): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            (string) ($config['host'] ?? '127.0.0.1'),
            (int) ($config['port'] ?? 3306),
            (string) ($config['database'] ?? ''),
            (string) ($config['charset'] ?? 'utf8mb4')
        );

        try {
            return new PDO($dsn, (string) ($config['username'] ?? ''), (string) ($config['password'] ?? ''), [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // Настоящие подготовленные выражения: параметр в запросе должен
                // встречаться один раз, зато данные точно не склеятся с SQL
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            throw new DatabaseException('Не удалось подключиться к MySQL: ' . $e->getMessage(), [], 0, $e);
        }
    }

    /**
     * Запрос, считающий строки для пагинации.
     *
     * Простую выборку считаем по тем же условиям, без обёртки в подзапрос: на
     * больших таблицах подзапрос материализуется и стоит в разы дороже. Всё
     * сложное (группировки, DISTINCT, объединения) — по-прежнему подзапросом.
     */
    private function countSql(string $sql): string
    {
        $normalized = trim((string) preg_replace('#\s+#', ' ', $sql));

        $simple = preg_match('#^SELECT (?!DISTINCT\b)(?:.+?) FROM (.+)$#i', $normalized, $matches) === 1
            && preg_match('#\b(GROUP BY|HAVING|UNION|LIMIT)\b#i', $normalized) !== 1;

        if (!$simple) {
            return 'SELECT COUNT(*) FROM (' . $sql . ') AS page_source';
        }

        return 'SELECT COUNT(*) FROM ' . preg_replace('#\s+ORDER BY\s+.+$#i', '', $matches[1]);
    }

    /**
     * @param array<string|int, mixed> $params
     */
    private function run(string $sql, array $params): PDOStatement
    {
        try {
            return $this->prepareAndRun($sql, $params);
        } catch (PDOException $e) {
            // Воркер живёт часами, и MySQL успевает закрыть соединение по
            // wait_timeout. Одна попытка переподключиться — и запрос повторяется
            if ($this->connectionLost($e)) {
                $this->reconnect();

                try {
                    return $this->prepareAndRun($sql, $params);
                } catch (PDOException $retry) {
                    $e = $retry;
                }
            }

            throw new DatabaseException('Ошибка запроса к базе: ' . $e->getMessage(), ['sql' => $sql], 0, $e);
        }
    }

    /**
     * @param array<string|int, mixed> $params
     */
    private function prepareAndRun(string $sql, array $params): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);

        foreach ($params as $key => $value) {
            $name = is_int($key) ? $key + 1 : ':' . ltrim((string) $key, ':');

            $type = match (true) {
                is_int($value)  => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_INT,
                $value === null => PDO::PARAM_NULL,
                default         => PDO::PARAM_STR,
            };

            $statement->bindValue($name, is_bool($value) ? (int) $value : $value, $type);
        }

        $started = microtime(true);

        $statement->execute();

        // Замер уходит профилировщику: он решит, писать ли медленный запрос
        // в лог и собирать ли полную картину для панели отладки
        Profiler::query($sql, (microtime(true) - $started) * 1000, $statement->rowCount());

        return $statement;
    }

    /**
     * Оборвалась ли связь. Внутри транзакции повторять нечего — она уже потеряна.
     */
    private function connectionLost(PDOException $e): bool
    {
        if ($this->driver !== self::MYSQL || $this->pdo->inTransaction()) {
            return false;
        }

        $code    = (int) ($e->errorInfo[1] ?? 0);
        $message = $e->getMessage();

        return in_array($code, [2006, 2013, 2055], true)
            || str_contains($message, 'server has gone away')
            || str_contains($message, 'Lost connection');
    }

    private function reconnect(): void
    {
        try {
            $this->pdo = $this->connectMysql($this->mysqlConfig());
        } catch (DatabaseException $e) {
            throw new DatabaseException('Соединение с базой потеряно и не восстановилось: ' . $e->getMessage(), [], 0, $e);
        }
    }
}
