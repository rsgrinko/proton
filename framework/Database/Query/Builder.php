<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Database\Query;

use Closure;
use Rsgrinko\Proton\Database\Connection;
use Rsgrinko\Proton\Database\DatabaseException;

/**
 * Построитель запросов.
 *
 *     $rows = $db->table('users')
 *         ->where('active', 1)
 *         ->whereIn('role_id', [1, 2])
 *         ->orderBy('created_at', 'desc')
 *         ->limit(20)
 *         ->get();
 *
 * Значения всегда уходят параметрами, имена таблиц и колонок сверяются регуляркой:
 * их параметром не подставить, а склеивать в SQL что попало нельзя. Каждое значение
 * получает своё имя параметра (p0, p1, …) — MySQL с настоящими подготовленными
 * выражениями не разрешает использовать один параметр дважды.
 */
class Builder
{
    protected Connection $db;

    protected string $table;

    /** @var array<int, string> */
    protected array $columns = ['*'];

    protected bool $distinct = false;

    /** @var array<int, array{type: string, sql: string, boolean: string}> */
    protected array $wheres = [];

    /** @var array<int, string> */
    protected array $joins = [];

    /** @var array<int, string> */
    protected array $groups = [];

    /** @var array<int, string> */
    protected array $havings = [];

    /** @var array<int, string> */
    protected array $orders = [];

    protected ?int $limit = null;

    protected ?int $offset = null;

    /** @var array<string, mixed> Значения параметров */
    protected array $bindings = [];

    /** Счётчик имён параметров */
    protected int $counter = 0;

    public function __construct(Connection $db, string $table)
    {
        $this->db    = $db;
        $this->table = $this->name($table);
    }

    public function connection(): Connection
    {
        return $this->db;
    }

    public function from(string $table): static
    {
        $this->table = $this->name($table);

        return $this;
    }

    /**
     * Колонки выборки. Выражения (COUNT(*), a.b AS c) допускаются как есть —
     * их пишет разработчик, а не пользователь.
     */
    public function select(string ...$columns): static
    {
        $this->columns = $columns === [] ? ['*'] : $columns;

        return $this;
    }

    public function addSelect(string $column): static
    {
        if ($this->columns === ['*']) {
            $this->columns = [];
        }

        $this->columns[] = $column;

        return $this;
    }

    public function distinct(bool $distinct = true): static
    {
        $this->distinct = $distinct;

        return $this;
    }

    /**
     * Условие. Два аргумента — равенство, три — со своим оператором.
     * Замыкание — скобка: ->where(function (Builder $q) { … }).
     */
    public function where(string|Closure $column, mixed $operator = null, mixed $value = null, string $boolean = 'AND'): static
    {
        if ($column instanceof Closure) {
            return $this->nested($column, $boolean);
        }

        if (func_num_args() === 2) {
            $value    = $operator;
            $operator = '=';
        }

        $operator = strtoupper(trim((string) $operator));

        if (!in_array($operator, ['=', '<>', '!=', '<', '<=', '>', '>=', 'LIKE', 'NOT LIKE'], true)) {
            throw new DatabaseException('Недопустимый оператор условия: ' . $operator);
        }

        if ($value === null) {
            return $operator === '=' ? $this->whereNull($column, $boolean) : $this->whereNotNull($column, $boolean);
        }

        $this->wheres[] = [
            'type'    => 'basic',
            'sql'     => $this->name($column) . ' ' . $operator . ' ' . $this->bind($value),
            'boolean' => $boolean,
        ];

        return $this;
    }

    public function orWhere(string|Closure $column, mixed $operator = null, mixed $value = null): static
    {
        if (func_num_args() === 2) {
            return $this->where($column, '=', $operator, 'OR');
        }

        return $this->where($column, $operator, $value, 'OR');
    }

    /**
     * @param array<int, mixed> $values
     */
    public function whereIn(string $column, array $values, string $boolean = 'AND', bool $not = false): static
    {
        if ($values === []) {
            // Пустой список: «ни одно значение не подходит» — и это не ошибка,
            // а нормальный результат фильтра, из которого ничего не выбрали
            $this->wheres[] = ['type' => 'basic', 'sql' => $not ? '1 = 1' : '1 = 0', 'boolean' => $boolean];

            return $this;
        }

        $placeholders = array_map(fn (mixed $value): string => $this->bind($value), array_values($values));

        $this->wheres[] = [
            'type'    => 'basic',
            'sql'     => $this->name($column) . ($not ? ' NOT IN (' : ' IN (') . implode(', ', $placeholders) . ')',
            'boolean' => $boolean,
        ];

        return $this;
    }

    /**
     * @param array<int, mixed> $values
     */
    public function whereNotIn(string $column, array $values, string $boolean = 'AND'): static
    {
        return $this->whereIn($column, $values, $boolean, true);
    }

    public function whereNull(string $column, string $boolean = 'AND'): static
    {
        $this->wheres[] = ['type' => 'basic', 'sql' => $this->name($column) . ' IS NULL', 'boolean' => $boolean];

        return $this;
    }

    public function whereNotNull(string $column, string $boolean = 'AND'): static
    {
        $this->wheres[] = ['type' => 'basic', 'sql' => $this->name($column) . ' IS NOT NULL', 'boolean' => $boolean];

        return $this;
    }

    public function whereBetween(string $column, mixed $from, mixed $to, string $boolean = 'AND'): static
    {
        $this->wheres[] = [
            'type'    => 'basic',
            'sql'     => $this->name($column) . ' BETWEEN ' . $this->bind($from) . ' AND ' . $this->bind($to),
            'boolean' => $boolean,
        ];

        return $this;
    }

    /**
     * Поиск подстроки. Служебные символы LIKE экранируем: иначе «100%» в поиске
     * превращается в «что угодно после 100».
     */
    public function whereLike(string $column, string $value, string $boolean = 'AND'): static
    {
        // Экранируем восклицательным знаком, а не обратным слэшем: ESCAPE '!'
        // пишется одинаково в SQLite и MySQL, а «\» в MySQL пришлось бы удваивать
        // ещё раз. Без ESCAPE поиск по «имя_файла» не нашёл бы ничего.
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);

        $this->wheres[] = [
            'type'    => 'basic',
            'sql'     => $this->name($column) . ' LIKE ' . $this->bind('%' . $escaped . '%') . " ESCAPE '!'",
            'boolean' => $boolean,
        ];

        return $this;
    }

    /**
     * Готовое условие со своими параметрами — там, где построителя не хватает.
     *
     * @param array<string, mixed> $bindings
     */
    public function whereRaw(string $sql, array $bindings = [], string $boolean = 'AND'): static
    {
        foreach ($bindings as $key => $value) {
            $this->bindings[ltrim((string) $key, ':')] = $value;
        }

        $this->wheres[] = ['type' => 'raw', 'sql' => $sql, 'boolean' => $boolean];

        return $this;
    }

    /**
     * Условие, только если значение чего-то стоит: удобно для фильтров списка.
     */
    public function when(mixed $condition, callable $callback): static
    {
        if ($condition !== null && $condition !== '' && $condition !== false && $condition !== []) {
            $callback($this, $condition);
        }

        return $this;
    }

    public function join(string $table, string $first, string $operator, string $second, string $type = 'INNER'): static
    {
        $type = strtoupper($type);

        if (!in_array($type, ['INNER', 'LEFT', 'RIGHT'], true)) {
            throw new DatabaseException('Недопустимый тип соединения: ' . $type);
        }

        if (!in_array($operator, ['=', '<', '<=', '>', '>=', '<>'], true)) {
            throw new DatabaseException('Недопустимый оператор соединения: ' . $operator);
        }

        $this->joins[] = $type . ' JOIN ' . $this->name($table) . ' ON '
            . $this->name($first) . ' ' . $operator . ' ' . $this->name($second);

        return $this;
    }

    public function leftJoin(string $table, string $first, string $operator, string $second): static
    {
        return $this->join($table, $first, $operator, $second, 'LEFT');
    }

    public function groupBy(string ...$columns): static
    {
        foreach ($columns as $column) {
            $this->groups[] = $this->name($column);
        }

        return $this;
    }

    /**
     * @param array<string, mixed> $bindings
     */
    public function having(string $sql, array $bindings = []): static
    {
        foreach ($bindings as $key => $value) {
            $this->bindings[ltrim((string) $key, ':')] = $value;
        }

        $this->havings[] = $sql;

        return $this;
    }

    public function orderBy(string $column, string $direction = 'asc'): static
    {
        $direction = strtolower($direction) === 'desc' ? 'DESC' : 'ASC';

        $this->orders[] = $this->name($column) . ' ' . $direction;

        return $this;
    }

    public function latest(string $column = 'created_at'): static
    {
        return $this->orderBy($column, 'desc');
    }

    public function limit(?int $limit): static
    {
        $this->limit = $limit === null ? null : max(0, $limit);

        return $this;
    }

    public function offset(int $offset): static
    {
        $this->offset = max(0, $offset);

        return $this;
    }

    /**
     * Строки запроса.
     *
     * @return array<int, array<string, mixed>>
     */
    public function get(): array
    {
        return $this->db->select($this->toSql(), $this->bindings);
    }

    /**
     * Первая строка или null.
     *
     * Тип возврата шире, чем массив: наследник для моделей отдаёт отсюда объект,
     * а сузить объявленный ?array он бы не смог.
     *
     * @return array<string, mixed>|object|null
     */
    public function first(): array|object|null
    {
        $limit = $this->limit;

        $this->limit(1);

        $rows = $this->get();

        $this->limit = $limit;

        return $rows[0] ?? null;
    }

    /**
     * Одно значение первой строки.
     */
    public function value(string $column): mixed
    {
        $row = (clone $this)->select($column)->first();

        if ($row === null) {
            return null;
        }

        return reset($row);
    }

    /**
     * Плоский список значений колонки, при желании с ключами.
     *
     * @return array<int|string, mixed>
     */
    public function pluck(string $column, ?string $key = null): array
    {
        $query = clone $this;

        $rows   = $key === null ? $query->select($column)->get() : $query->select($key, $column)->get();
        $result = [];

        foreach ($rows as $row) {
            $short = $this->shortName($column);

            if ($key === null) {
                $result[] = $row[$short] ?? null;

                continue;
            }

            $result[(string) ($row[$this->shortName($key)] ?? '')] = $row[$short] ?? null;
        }

        return $result;
    }

    public function count(string $column = '*'): int
    {
        $query = clone $this;

        $query->orders = [];

        return (int) $query->db->value(
            $query->select('COUNT(' . ($column === '*' ? '*' : $this->name($column)) . ') AS aggregate')->toSql(),
            $query->bindings
        );
    }

    public function sum(string $column): float
    {
        $query = clone $this;

        $query->orders = [];

        return (float) $query->db->value(
            $query->select('SUM(' . $this->name($column) . ') AS aggregate')->toSql(),
            $query->bindings
        );
    }

    public function max(string $column): mixed
    {
        $query = clone $this;

        $query->orders = [];

        return $query->db->value(
            $query->select('MAX(' . $this->name($column) . ') AS aggregate')->toSql(),
            $query->bindings
        );
    }

    public function exists(): bool
    {
        return (clone $this)->limit(1)->first() !== null;
    }

    /**
     * Страница результатов вместе со счётчиком — для списков в панели.
     *
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, pages: int, per_page: int}
     */
    public function paginate(int $page = 1, int $perPage = 30): array
    {
        $total = $this->count();

        $pages = max(1, (int) ceil($total / max(1, $perPage)));
        $page  = max(1, min($page, $pages));

        $items = (clone $this)->limit($perPage)->offset(($page - 1) * $perPage)->get();

        return [
            'items'    => $items,
            'total'    => $total,
            'page'     => $page,
            'pages'    => $pages,
            'per_page' => $perPage,
        ];
    }

    /**
     * Обходит таблицу порциями — чтобы не поднимать в память миллион строк.
     * Замыкание вернуло false — обход прекращается.
     *
     * @param callable(array<int, array<string, mixed>>): (bool|void) $callback
     */
    public function chunk(int $size, callable $callback): void
    {
        $page = 1;

        do {
            $rows = (clone $this)->limit($size)->offset(($page - 1) * $size)->get();

            if ($rows === []) {
                return;
            }

            if ($callback($rows) === false) {
                return;
            }

            $page++;
        } while (count($rows) === $size);
    }

    /**
     * Вставка. Возвращает id новой записи.
     *
     * @param array<string, mixed> $data
     */
    public function insert(array $data): int
    {
        return $this->db->insert($this->table, $data);
    }

    /**
     * Обновление по накопленным условиям.
     *
     * @param array<string, mixed> $data
     */
    public function update(array $data): int
    {
        if ($data === []) {
            return 0;
        }

        $set = [];

        foreach ($data as $column => $value) {
            $set[] = $this->name($column) . ' = ' . $this->bind($value, 'set');
        }

        $sql = 'UPDATE ' . $this->table . ' SET ' . implode(', ', $set) . $this->whereSql();

        return $this->db->execute($sql, $this->bindings);
    }

    /**
     * Увеличить числовую колонку — счётчики просмотров и попыток.
     */
    public function increment(string $column, int $amount = 1): int
    {
        $column = $this->name($column);

        return $this->db->execute(
            'UPDATE ' . $this->table . ' SET ' . $column . ' = ' . $column . ' + ' . (int) $amount . $this->whereSql(),
            $this->bindings
        );
    }

    /**
     * Удаление по накопленным условиям. Без условий удалять отказываемся:
     * «снести всю таблицу» должно писаться явно, а не получаться по забывчивости.
     */
    public function delete(): int
    {
        if ($this->wheres === []) {
            throw new DatabaseException('Удаление без условий запрещено: добавьте where() или зовите truncate()');
        }

        return $this->db->execute('DELETE FROM ' . $this->table . $this->whereSql(), $this->bindings);
    }

    /**
     * Собранный SQL — пригождается в отладке и тестах.
     */
    public function toSql(): string
    {
        $sql = 'SELECT ' . ($this->distinct ? 'DISTINCT ' : '') . implode(', ', $this->columns)
            . ' FROM ' . $this->table;

        if ($this->joins !== []) {
            $sql .= ' ' . implode(' ', $this->joins);
        }

        $sql .= $this->whereSql();

        if ($this->groups !== []) {
            $sql .= ' GROUP BY ' . implode(', ', $this->groups);
        }

        if ($this->havings !== []) {
            $sql .= ' HAVING ' . implode(' AND ', $this->havings);
        }

        if ($this->orders !== []) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orders);
        }

        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . $this->limit;
        }

        if ($this->offset !== null && $this->offset > 0) {
            // В обеих базах OFFSET без LIMIT не работает — подставляем предел сами
            $sql .= ($this->limit === null ? ' LIMIT 18446744073709551615' : '') . ' OFFSET ' . $this->offset;
        }

        return $sql;
    }

    /**
     * Значения параметров запроса.
     *
     * @return array<string, mixed>
     */
    public function bindings(): array
    {
        return $this->bindings;
    }

    protected function whereSql(): string
    {
        if ($this->wheres === []) {
            return '';
        }

        $sql = '';

        foreach ($this->wheres as $index => $where) {
            $sql .= ($index === 0 ? '' : ' ' . $where['boolean'] . ' ') . $where['sql'];
        }

        return ' WHERE ' . $sql;
    }

    /**
     * Скобка из замыкания: ->where(function (Builder $q) { $q->where(…)->orWhere(…); }).
     */
    protected function nested(Closure $callback, string $boolean): static
    {
        $nested = new self($this->db, $this->table);

        // Счётчик общий, иначе имена параметров во вложенной части столкнутся с нашими
        $nested->counter = $this->counter;

        $callback($nested);

        $this->counter = $nested->counter;

        if ($nested->wheres === []) {
            return $this;
        }

        $this->bindings = array_merge($this->bindings, $nested->bindings);

        $this->wheres[] = [
            'type'    => 'nested',
            'sql'     => '(' . substr($nested->whereSql(), 7) . ')',
            'boolean' => $boolean,
        ];

        return $this;
    }

    /**
     * Кладёт значение в параметры и возвращает его имя для SQL.
     */
    protected function bind(mixed $value, string $prefix = 'p'): string
    {
        $name = $prefix . $this->counter++;

        $this->bindings[$name] = $value;

        return ':' . $name;
    }

    /**
     * Имя таблицы или колонки. Разрешаем «таблица.колонка» и звёздочку —
     * всё остальное отвергаем: подставлять в SQL непонятно что нельзя.
     */
    protected function name(string $name): string
    {
        $name = trim($name);

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*|\.\*)?$/', $name) !== 1) {
            throw new DatabaseException('Недопустимое имя таблицы или колонки: ' . $name);
        }

        return $name;
    }

    /**
     * Имя колонки без таблицы: строки из базы приходят с короткими ключами.
     */
    protected function shortName(string $column): string
    {
        $dot = strrpos($column, '.');

        return $dot === false ? $column : substr($column, $dot + 1);
    }
}
