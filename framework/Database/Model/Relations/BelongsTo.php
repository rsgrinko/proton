<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Database\Model\Relations;

use Rsgrinko\Proton\Database\Model\Model;

/**
 * «Мой хозяин»: внешний ключ лежит у нас. У заметки есть автор —
 * $note->belongsTo(User::class) по note.user_id.
 */
final class BelongsTo extends Relation
{
    public function result(): ?Model
    {
        $key = $this->parent->raw($this->foreignKey);

        if ($key === null || $key === '' || $key === 0) {
            return null;
        }

        return $this->query()->where($this->localKey, $key)->first();
    }

    /**
     * @param array<int, Model> $models
     */
    public function load(string $name, array $models): void
    {
        $keys = $this->keys($models, $this->foreignKey);

        if ($keys === []) {
            return;
        }

        $owners = [];

        foreach ($this->query()->whereIn($this->localKey, $keys)->get() as $owner) {
            $owners[(string) $owner->raw($this->localKey)] = $owner;
        }

        foreach ($models as $model) {
            $model->setRelation($name, $owners[(string) $model->raw($this->foreignKey)] ?? null);
        }
    }
}
