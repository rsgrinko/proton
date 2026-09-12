<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Database\Model\Relations;

use Rsgrinko\Proton\Database\Connection;
use Rsgrinko\Proton\Database\Model\Model;

/**
 * «Многие ко многим» через таблицу-связку: у роли много прав, у пользователя
 * много проектов. Сама связка — обычная таблица из двух ключей, своей модели
 * ей не нужно.
 */
final class BelongsToMany extends Relation
{
    private string $pivot;

    /** Колонка связки, указывающая на связанную модель */
    private string $relatedKey;

    /**
     * @param class-string<Model> $related
     */
    public function __construct(Model $parent, string $related, string $pivot, string $foreignKey, string $relatedKey)
    {
        parent::__construct($parent, $related, $foreignKey, $parent->keyName());

        $this->pivot      = $pivot;
        $this->relatedKey = $relatedKey;
    }

    /**
     * @return array<int, Model>
     */
    public function result(): array
    {
        $key = $this->parent->raw($this->localKey);

        if ($key === null) {
            return [];
        }

        $ids = $this->db()->table($this->pivot)
            ->where($this->foreignKey, $key)
            ->pluck($this->relatedKey);

        if ($ids === []) {
            return [];
        }

        $instance = new ($this->related)();

        return $this->query()->whereIn($instance->keyName(), array_values($ids))->get();
    }

    /**
     * Привязать запись, если её ещё нет в связке.
     */
    public function attach(int|string $id): void
    {
        $exists = $this->db()->table($this->pivot)
            ->where($this->foreignKey, $this->parent->raw($this->localKey))
            ->where($this->relatedKey, $id)
            ->exists();

        if ($exists) {
            return;
        }

        $this->db()->insert($this->pivot, [
            $this->foreignKey => $this->parent->raw($this->localKey),
            $this->relatedKey => $id,
        ]);
    }

    /**
     * Отвязать запись.
     */
    public function detach(int|string $id): void
    {
        $this->db()->delete($this->pivot, [
            $this->foreignKey => $this->parent->raw($this->localKey),
            $this->relatedKey => $id,
        ]);
    }

    /**
     * Оставить в связке ровно этот список: чего нет — добавить, лишнее — убрать.
     *
     * @param array<int, int|string> $ids
     */
    public function sync(array $ids): void
    {
        $current = array_map(
            'strval',
            array_values($this->db()->table($this->pivot)
                ->where($this->foreignKey, $this->parent->raw($this->localKey))
                ->pluck($this->relatedKey))
        );

        $wanted = array_map('strval', $ids);

        foreach (array_diff($wanted, $current) as $id) {
            $this->attach($id);
        }

        foreach (array_diff($current, $wanted) as $id) {
            $this->detach($id);
        }
    }

    /**
     * @param array<int, Model> $models
     */
    public function load(string $name, array $models): void
    {
        $keys = $this->keys($models, $this->localKey);

        if ($keys === []) {
            return;
        }

        $links = $this->db()->table($this->pivot)
            ->whereIn($this->foreignKey, $keys)
            ->get();

        if ($links === []) {
            foreach ($models as $model) {
                $model->setRelation($name, []);
            }

            return;
        }

        $instance = new ($this->related)();
        $ids      = array_values(array_unique(array_map(
            fn (array $row): mixed => $row[$this->relatedKey],
            $links
        )));

        $related = [];

        foreach ($this->query()->whereIn($instance->keyName(), $ids)->get() as $model) {
            $related[(string) $model->key()] = $model;
        }

        $grouped = [];

        foreach ($links as $link) {
            $target = $related[(string) $link[$this->relatedKey]] ?? null;

            if ($target !== null) {
                $grouped[(string) $link[$this->foreignKey]][] = $target;
            }
        }

        foreach ($models as $model) {
            $model->setRelation($name, $grouped[(string) $model->raw($this->localKey)] ?? []);
        }
    }

    private function db(): Connection
    {
        return $this->parent->connection();
    }
}
