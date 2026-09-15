<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Database\Model;

use Rsgrinko\Proton\Database\Query\Builder;

/**
 * Построитель запросов, который отдаёт не массивы, а модели.
 *
 * Всё, что умеет обычный Builder (where, join, orderBy, paginate), работает и здесь —
 * различие только в том, что приходит на выходе. Отдельный класс, а не флаг в
 * построителе: возвращаемый тип должен быть понятен по коду, а не по настройке.
 */
final class Query extends Builder
{
    /** @var class-string<Model> */
    private string $model;

    /** @var array<int, string> Связи, которые нужно подгрузить сразу */
    private array $with = [];

    /** Показывать ли мягко удалённые записи */
    private bool $withTrashed = false;

    /** Только мягко удалённые */
    private bool $onlyTrashed = false;

    /**
     * @param class-string<Model> $model
     */
    public function __construct(string $model)
    {
        $instance = new $model();

        parent::__construct($instance->connection(), $instance->table());

        $this->model = $model;
    }

    /**
     * Подгрузить связи одним запросом на связь, а не по запросу на строку.
     */
    public function with(string ...$relations): self
    {
        foreach ($relations as $relation) {
            $this->with[] = $relation;
        }

        return $this;
    }

    /**
     * Вместе с мягко удалёнными.
     */
    public function withTrashed(bool $with = true): self
    {
        $this->withTrashed = $with;

        return $this;
    }

    /**
     * Только мягко удалённые — корзина.
     */
    public function onlyTrashed(): self
    {
        $this->onlyTrashed = true;

        return $this;
    }

    /**
     * Модели по запросу.
     *
     * @return array<int, Model>
     */
    public function get(): array
    {
        $this->applyTrashed();

        $models = [];

        foreach (parent::get() as $row) {
            $models[] = ($this->model)::fromRow($row);
        }

        if ($models !== [] && $this->with !== []) {
            $this->loadRelations($models);
        }

        return $models;
    }

    /**
     * Строки как есть, без превращения в модели — нужно агрегатам и pluck.
     *
     * @return array<int, array<string, mixed>>
     */
    public function rows(): array
    {
        $this->applyTrashed();

        return parent::get();
    }

    /**
     * Первая модель или null.
     */
    public function first(): ?Model
    {
        $models = (clone $this)->limit(1)->get();

        return $models[0] ?? null;
    }

    /**
     * Первая модель или исключение — когда запись обязана быть.
     */
    public function firstOrFail(): Model
    {
        $model = $this->first();

        if ($model === null) {
            throw new RecordNotFound('Запись не найдена: ' . $this->model);
        }

        return $model;
    }

    /**
     * Одно значение — берём из сырой строки, модель тут не нужна.
     */
    public function value(string $column): mixed
    {
        $row = (clone $this)->select($column)->limit(1)->rows()[0] ?? null;

        if ($row === null) {
            return null;
        }

        return reset($row);
    }

    public function count(string $column = '*'): int
    {
        $this->applyTrashed();

        return parent::count($column);
    }

    /**
     * Страница моделей.
     *
     * @return array{items: array<int, Model>, total: int, page: int, pages: int, per_page: int}
     */
    public function paginate(int $page = 1, int $perPage = 30): array
    {
        $total = $this->count();
        $pages = max(1, (int) ceil($total / max(1, $perPage)));
        $page  = max(1, min($page, $pages));

        return [
            'items'    => (clone $this)->limit($perPage)->offset(($page - 1) * $perPage)->get(),
            'total'    => $total,
            'page'     => $page,
            'pages'    => $pages,
            'per_page' => $perPage,
        ];
    }

    /**
     * Обновление через модель проставляет отметку времени — иначе updated_at
     * молча отстаёт от данных.
     *
     * @param array<string, mixed> $data
     */
    public function update(array $data): int
    {
        $model = new ($this->model)();

        // Отметку ставим, только если колонка есть: у журналов и событий
        // её обычно нет — там только created_at
        if ($model->hasTimestamps() && $model->usesColumn('updated_at') && !array_key_exists('updated_at', $data)) {
            $data['updated_at'] = date('Y-m-d H:i:s');
        }

        $this->applyTrashed();

        return parent::update($data);
    }

    /**
     * Мягкое удаление, если модель его поддерживает, иначе обычное.
     */
    public function delete(): int
    {
        $model = new ($this->model)();

        if ($model->softDeletes()) {
            return parent::update([Model::DELETED_AT => date('Y-m-d H:i:s')]);
        }

        return parent::delete();
    }

    /**
     * Настоящее удаление, даже если включено мягкое.
     */
    public function forceDelete(): int
    {
        return parent::delete();
    }

    /**
     * Условие по мягкому удалению добавляется один раз и перед самым запросом:
     * до этого момента ещё может прийти withTrashed().
     */
    private function applyTrashed(): void
    {
        static $applied = false;

        $model = new ($this->model)();

        if (!$model->softDeletes() || $this->withTrashed) {
            return;
        }

        // Повторный вызов (count(), затем get()) не должен добавлять условие дважды
        foreach ($this->wheres as $where) {
            if (str_contains($where['sql'], Model::DELETED_AT)) {
                return;
            }
        }

        if ($this->onlyTrashed) {
            $this->whereNotNull(Model::DELETED_AT);

            return;
        }

        $this->whereNull(Model::DELETED_AT);
    }

    /**
     * Подгружает объявленные связи: один запрос на связь.
     *
     * @param array<int, Model> $models
     */
    private function loadRelations(array $models): void
    {
        foreach ($this->with as $name) {
            $relation = $models[0]->relation($name);

            $relation->load($name, $models);
        }
    }
}
