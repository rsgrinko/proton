<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Database\Model\Relations;

use Rsgrinko\Proton\Database\Model\Model;

/**
 * «Много моих»: чужие записи ссылаются на нас своим внешним ключом.
 * У пользователя — заметки: $user->hasMany(Note::class) по note.user_id.
 */
class HasMany extends Relation
{
    /**
     * @return array<int, Model>
     */
    public function result(): array
    {
        $key = $this->parent->raw($this->localKey);

        if ($key === null) {
            return [];
        }

        return $this->query()->where($this->foreignKey, $key)->get();
    }

    /**
     * Новая запись на этой стороне связи — внешний ключ проставляется сам.
     *
     * @param array<string, mixed> $attributes
     */
    public function create(array $attributes): Model
    {
        $model = new ($this->related)($attributes);

        $model->setAttribute($this->foreignKey, $this->parent->raw($this->localKey));
        $model->save();

        return $model;
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

        $grouped = [];

        foreach ($this->query()->whereIn($this->foreignKey, $keys)->get() as $related) {
            $grouped[(string) $related->raw($this->foreignKey)][] = $related;
        }

        foreach ($models as $model) {
            $model->setRelation($name, $grouped[(string) $model->raw($this->localKey)] ?? []);
        }
    }
}
