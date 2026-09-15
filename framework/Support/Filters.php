<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Support;

use Rsgrinko\Proton\Database\Query\Builder;
use Rsgrinko\Proton\Http\Request;

/**
 * Фильтры и сортировка списка: разбирает параметры адреса, подмешивает их
 * в запрос и отдаёт вьюхе то, из чего рисуется форма и ссылки постраничной
 * навигации.
 *
 *     $filters = Filters::make($request, [
 *         Filter::search('q', 'Поиск', ['login', 'email']),
 *         Filter::select('role_id', 'Роль', $roles),
 *     ])->sortable(['id', 'login'], 'id');
 *
 *     $page = $filters->apply(User::query())->paginate($this->page($request), $this->perPage());
 *
 * Негодное значение из адреса не роняет страницу, а отбрасывается: список —
 * не форма, показывать ошибку тут некому, а ссылку правят руками сплошь и рядом.
 * В SQL при этом уходят только имена колонок из кода и подготовленные значения.
 */
final class Filters
{
    /** @var array<int, Filter> */
    private array $fields = [];

    /** @var array<string, string> Значения параметров адреса */
    private array $values = [];

    /** @var array<int, string> Колонки, по которым разрешено сортировать */
    private array $sortable = [];

    private string $sort = '';

    private string $direction = 'asc';

    private ?Request $request = null;

    /**
     * @param array<int, Filter> $fields
     */
    public static function make(Request $request, array $fields): self
    {
        $filters = new self();
        $filters->fields = $fields;
        $filters->request = $request;

        $input = [];
        $rules = [];

        foreach ($fields as $field) {
            $rules += $field->rules();

            foreach ($field->keys() as $key) {
                $value = $request->query($key, '');

                $input[$key] = is_scalar($value) ? trim((string) $value) : '';
            }
        }

        $validator = Validator::make($input, $rules);

        // Проверку нужно прогнать, чтобы появились ошибки; исключение тут не
        // нужно — негодное значение просто не попадёт в запрос
        $validator->passes();

        $errors = $validator->errors();

        foreach ($input as $key => $value) {
            $filters->values[$key] = isset($errors[$key]) ? '' : $value;
        }

        return $filters;
    }

    /**
     * Разрешённые колонки сортировки. Белый список обязателен: имя колонки
     * подставляется в ORDER BY, и брать его прямо из адреса нельзя.
     *
     * @param array<int, string> $columns
     */
    public function sortable(array $columns, string $default = '', string $direction = 'desc'): self
    {
        $this->sortable  = $columns;
        $this->sort      = $default;
        $this->direction = strtolower($direction) === 'asc' ? 'asc' : 'desc';

        // Сортировку из адреса читаем здесь: раньше просто не с чем сверять
        $sort = (string) ($this->request?->query('sort', '') ?? '');

        if (in_array($sort, $this->sortable, true)) {
            $this->sort      = $sort;
            $this->direction = strtolower((string) ($this->request?->query('dir', '') ?? '')) === 'asc' ? 'asc' : 'desc';
        }

        return $this;
    }

    /**
     * Можно ли сортировать по колонке — вьюхе, чтобы не рисовать ссылку там,
     * где она ничего не даст.
     */
    public function sortableBy(string $column): bool
    {
        return in_array($column, $this->sortable, true);
    }

    /**
     * Значение фильтра — вьюхе, чтобы показать заполненную форму.
     */
    public function value(string $key): string
    {
        return $this->values[$key] ?? '';
    }

    /**
     * Хоть что-то выбрано — значит, есть что сбрасывать.
     */
    public function active(): bool
    {
        foreach ($this->values as $value) {
            if ($value !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Непустые параметры вместе с сортировкой: их тащат за собой ссылки
     * постраничной навигации, заголовки колонок и выгрузка.
     *
     * @return array<string, string>
     */
    public function params(): array
    {
        $params = array_filter($this->values, static fn (string $value): bool => $value !== '');

        if ($this->sort !== '') {
            $params['sort'] = $this->sort;
            $params['dir']  = $this->direction;
        }

        return $params;
    }

    /**
     * @return array<int, Filter>
     */
    public function fields(): array
    {
        return $this->fields;
    }

    public function sort(): string
    {
        return $this->sort;
    }

    public function direction(): string
    {
        return $this->direction;
    }

    /**
     * Условия и порядок — в запрос.
     *
     * @template T of Builder
     *
     * @param T $query
     *
     * @return T
     */
    public function apply(Builder $query): Builder
    {
        foreach ($this->fields as $field) {
            $this->applyField($query, $field);
        }

        if ($this->sort !== '') {
            $query->orderBy($this->sort, $this->direction);
        }

        return $query;
    }

    private function applyField(Builder $query, Filter $field): void
    {
        $value = $this->value($field->key);

        switch ($field->type) {
            case Filter::SEARCH:
                if ($value === '') {
                    return;
                }

                $columns = $field->columns;

                $query->where(static function (Builder $nested) use ($columns, $value): void {
                    foreach ($columns as $index => $column) {
                        $nested->whereLike($column, $value, $index === 0 ? 'AND' : 'OR');
                    }
                });

                return;

            case Filter::SELECT:
            case Filter::TEXT:
            case Filter::FLAG:
                if ($value === '') {
                    return;
                }

                $query->where($field->column(), '=', $value);

                return;

            case Filter::FILLED:
                if ($value === '') {
                    return;
                }

                $value === '1' ? $query->whereNotNull($field->column()) : $query->whereNull($field->column());

                return;

            case Filter::DATES:
                $from = $this->value($field->key . '_from');
                $to   = $this->value($field->key . '_to');

                // Граница «по» включительно: человек имеет в виду весь день
                if ($from !== '') {
                    $query->where($field->column(), '>=', $from . ' 00:00:00');
                }

                if ($to !== '') {
                    $query->where($field->column(), '<=', $to . ' 23:59:59');
                }
        }
    }
}
