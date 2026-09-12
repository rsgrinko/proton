<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Database\Model\Relations;

use Rsgrinko\Proton\Database\Model\Model;
use Rsgrinko\Proton\Database\Model\Query;

/**
 * Связь между моделями.
 *
 * Умеет две вещи: достать результат для одной записи ($user->posts) и подгрузить
 * связь сразу для списка записей (Query::with('posts')) — одним запросом на связь,
 * а не по запросу на строку. Второе и есть причина, по которой связь оформлена
 * объектом, а не методом, возвращающим сразу данные.
 */
abstract class Relation
{
    protected Model $parent;

    /** @var class-string<Model> */
    protected string $related;

    protected string $foreignKey;

    protected string $localKey;

    /**
     * @param class-string<Model> $related
     */
    public function __construct(Model $parent, string $related, string $foreignKey, string $localKey)
    {
        $this->parent     = $parent;
        $this->related    = $related;
        $this->foreignKey = $foreignKey;
        $this->localKey   = $localKey;
    }

    /**
     * Построитель по связанной модели — на него можно навесить свои условия:
     * $user->posts()->where('published', 1)->get().
     */
    public function query(): Query
    {
        return ($this->related)::query();
    }

    /**
     * Результат связи для одной записи.
     */
    abstract public function result(): mixed;

    /**
     * Подгружает связь сразу для списка моделей.
     *
     * @param array<int, Model> $models
     */
    abstract public function load(string $name, array $models): void;

    /**
     * Собирает значения ключа у списка моделей — по ним и идёт один общий запрос.
     *
     * @param array<int, Model> $models
     *
     * @return array<int, mixed>
     */
    protected function keys(array $models, string $column): array
    {
        $keys = [];

        foreach ($models as $model) {
            $value = $model->raw($column);

            if ($value !== null && !in_array($value, $keys, true)) {
                $keys[] = $value;
            }
        }

        return $keys;
    }
}
