<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Database\Schema;

/**
 * Описание таблицы: колонки и индексы. Миграция получает его в замыкании и
 * перечисляет, что нужно; SQL под нужный диалект собирается уже здесь.
 *
 *     $this->create('users', function (Blueprint $table) {
 *         $table->id();
 *         $table->string('login', 191);
 *         $table->timestamps();
 *         $table->unique('idx_users_login', 'login');
 *     });
 */
final class Blueprint
{
    private const INDEX    = 'index';
    private const UNIQUE   = 'unique';
    private const FULLTEXT = 'fulltext';

    private string $table;

    /** @var array<int, Column> */
    private array $columns = [];

    /** @var array<int, array{name: string, columns: string, type: string}> */
    private array $indexes = [];

    /** @var array<int, string> */
    private array $dropColumns = [];

    /** @var array<int, string> */
    private array $dropIndexes = [];

    public function __construct(string $table)
    {
        $this->table = $table;
    }

    public function table(): string
    {
        return $this->table;
    }

    // --- Колонки -------------------------------------------------------------

    /**
     * Первичный ключ с автоинкрементом.
     */
    public function id(string $name = 'id'): Column
    {
        return $this->addColumn(new Column($name, 'id'));
    }

    public function string(string $name, int $length = 191): Column
    {
        return $this->addColumn(new Column($name, 'string', $length));
    }

    public function text(string $name): Column
    {
        return $this->addColumn(new Column($name, 'text'));
    }

    /**
     * Длинный текст: тела писем и сырой HTML в MySQL не влезают в TEXT.
     */
    public function longText(string $name): Column
    {
        return $this->addColumn(new Column($name, 'longText'));
    }

    public function integer(string $name): Column
    {
        return $this->addColumn(new Column($name, 'integer'));
    }

    public function boolean(string $name): Column
    {
        return $this->addColumn(new Column($name, 'boolean'));
    }

    public function decimal(string $name, int $total = 12, int $scale = 2): Column
    {
        return $this->addColumn(new Column($name, 'decimal', $total, $scale));
    }

    public function float(string $name): Column
    {
        return $this->addColumn(new Column($name, 'float'));
    }

    public function dateTime(string $name): Column
    {
        return $this->addColumn(new Column($name, 'dateTime'));
    }

    public function date(string $name): Column
    {
        return $this->addColumn(new Column($name, 'date'));
    }

    /**
     * JSON храним текстом: обе базы умеют свой тип json, но искать по нему всё
     * равно приходится по-разному, а модель разбирает поле сама (casts).
     */
    public function json(string $name): Column
    {
        return $this->addColumn(new Column($name, 'text'));
    }

    /**
     * Внешний ключ на чужую таблицу: колонка плюс индекс по ней.
     * Настоящий FOREIGN KEY не ставим — в SQLite его потом не изменить,
     * а целостность в приложении всё равно проверяется кодом.
     */
    public function foreignId(string $name, bool $index = true): Column
    {
        $column = $this->integer($name)->default(0);

        if ($index) {
            $this->index('idx_' . $this->table . '_' . $name, $name);
        }

        return $column;
    }

    /**
     * Отметки времени: их сама проставляет модель.
     */
    public function timestamps(): void
    {
        $this->dateTime('created_at')->nullable();
        $this->dateTime('updated_at')->nullable();
    }

    /**
     * Колонка мягкого удаления.
     */
    public function softDeletes(): void
    {
        $this->dateTime('deleted_at')->nullable();
    }

    /**
     * Удалить колонку. SQLite умеет это с версии 3.35; если колонка входит
     * в индекс, индекс снимается раньше — dropIndex() выполняется первым.
     */
    public function dropColumn(string $name): void
    {
        $this->dropColumns[] = $name;
    }

    // --- Индексы -------------------------------------------------------------

    /**
     * @param string|array<int, string> $columns
     */
    public function index(string $name, string|array $columns): void
    {
        $this->indexes[] = ['name' => $name, 'columns' => $this->columnList($columns), 'type' => self::INDEX];
    }

    /**
     * @param string|array<int, string> $columns
     */
    public function unique(string $name, string|array $columns): void
    {
        $this->indexes[] = ['name' => $name, 'columns' => $this->columnList($columns), 'type' => self::UNIQUE];
    }

    /**
     * Полнотекстовый индекс. Есть только в MySQL — в SQLite поиск остаётся на LIKE.
     *
     * @param string|array<int, string> $columns
     */
    public function fulltext(string $name, string|array $columns): void
    {
        $this->indexes[] = ['name' => $name, 'columns' => $this->columnList($columns), 'type' => self::FULLTEXT];
    }

    public function dropIndex(string $name): void
    {
        $this->dropIndexes[] = $name;
    }

    // --- Сборка SQL ----------------------------------------------------------

    /**
     * Шаги создания таблицы: сама таблица и её индексы.
     *
     * @return array<int, Operation>
     */
    public function createOperations(Types $types): array
    {
        $definitions = [];

        foreach ($this->columns as $column) {
            $definitions[] = '    ' . $column->sql($types);
        }

        $steps = [Operation::raw(
            'CREATE TABLE IF NOT EXISTS ' . $this->table . " (\n" . implode(",\n", $definitions) . "\n)" . $types->tableSuffix()
        )];

        foreach ($this->indexes as $index) {
            $sql = $this->createIndexSql($types, $index);

            if ($sql !== '') {
                $steps[] = Operation::createIndex($sql, $this->table, $index['name']);
            }
        }

        return $steps;
    }

    /**
     * Шаги правки существующей таблицы. Порядок важен: индексы снимаются раньше,
     * чем уходят их колонки, а новый индекс строится по уже добавленной.
     *
     * @return array<int, Operation>
     */
    public function alterOperations(Types $types): array
    {
        $steps = [];

        foreach ($this->dropIndexes as $name) {
            $steps[] = Operation::dropIndex($this->dropIndexSql($types, $name), $this->table, $name);
        }

        foreach ($this->dropColumns as $name) {
            $steps[] = Operation::dropColumn(
                'ALTER TABLE ' . $this->table . ' DROP COLUMN ' . $name,
                $this->table,
                $name
            );
        }

        foreach ($this->columns as $column) {
            $steps[] = Operation::addColumn(
                'ALTER TABLE ' . $this->table . ' ADD COLUMN ' . $column->sql($types),
                $this->table,
                $column->name()
            );
        }

        foreach ($this->indexes as $index) {
            $sql = $this->createIndexSql($types, $index);

            if ($sql !== '') {
                $steps[] = Operation::createIndex($sql, $this->table, $index['name']);
            }
        }

        return $steps;
    }

    /**
     * @param array{name: string, columns: string, type: string} $index
     */
    private function createIndexSql(Types $types, array $index): string
    {
        if ($index['type'] === self::FULLTEXT) {
            return $types->isSqlite()
                ? ''
                : 'ALTER TABLE ' . $this->table . ' ADD FULLTEXT INDEX ' . $index['name'] . ' (' . $index['columns'] . ')';
        }

        $unique = $index['type'] === self::UNIQUE ? 'UNIQUE ' : '';

        // SQLite умеет IF NOT EXISTS, MySQL — нет, но шаг и так проверяется перед выполнением
        return $types->isSqlite()
            ? 'CREATE ' . $unique . 'INDEX IF NOT EXISTS ' . $index['name'] . ' ON ' . $this->table . ' (' . $index['columns'] . ')'
            : 'CREATE ' . $unique . 'INDEX ' . $index['name'] . ' ON ' . $this->table . ' (' . $index['columns'] . ')';
    }

    private function dropIndexSql(Types $types, string $name): string
    {
        return $types->isSqlite()
            ? 'DROP INDEX IF EXISTS ' . $name
            : 'DROP INDEX ' . $name . ' ON ' . $this->table;
    }

    private function addColumn(Column $column): Column
    {
        $this->columns[] = $column;

        return $column;
    }

    /**
     * @param string|array<int, string> $columns
     */
    private function columnList(string|array $columns): string
    {
        return is_array($columns) ? implode(', ', $columns) : $columns;
    }
}
