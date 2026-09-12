<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Database\Model\Relations;

use Rsgrinko\Proton\Database\Model\Model;

/**
 * «Один мой»: то же, что HasMany, только запись ожидается одна —
 * профиль пользователя, настройки, последняя сессия.
 */
final class HasOne extends HasMany
{
    public function result(): ?Model
    {
        $key = $this->parent->raw($this->localKey);

        if ($key === null) {
            return null;
        }

        return $this->query()->where($this->foreignKey, $key)->first();
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

        $found = [];

        foreach ($this->query()->whereIn($this->foreignKey, $keys)->get() as $related) {
            $found[(string) $related->raw($this->foreignKey)] ??= $related;
        }

        foreach ($models as $model) {
            $model->setRelation($name, $found[(string) $model->raw($this->localKey)] ?? null);
        }
    }
}
