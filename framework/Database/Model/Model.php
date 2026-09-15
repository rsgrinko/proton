<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Database\Model;

use JsonSerializable;
use Rsgrinko\Proton\Database\Connection;
use Rsgrinko\Proton\Database\Model\Relations\BelongsTo;
use Rsgrinko\Proton\Database\Model\Relations\BelongsToMany;
use Rsgrinko\Proton\Database\Model\Relations\HasMany;
use Rsgrinko\Proton\Database\Model\Relations\HasOne;
use Rsgrinko\Proton\Database\Model\Relations\Relation;
use Rsgrinko\Proton\Support\ProtonException;
use Rsgrinko\Proton\Support\Str;

/**
 * Модель: строка таблицы как объект (Active Record).
 *
 *     class User extends Model
 *     {
 *         protected static string $table = 'users';
 *         protected array $fillable = ['login', 'email', 'name'];
 *         protected array $casts = ['active' => 'bool', 'settings' => 'json'];
 *
 *         public function posts(): HasMany
 *         {
 *             return $this->hasMany(Post::class);
 *         }
 *     }
 *
 *     $user = User::where('login', 'ivan')->first();
 *     $user->name = 'Иван';
 *     $user->save();
 *
 * Имя таблицы можно не задавать — оно берётся из имени класса (User -> users).
 * Отметки created_at/updated_at проставляются сами, если в таблице есть такие
 * колонки; мягкое удаление включается свойством $softDelete.
 *
 * Массовое присвоение работает по «белому списку» $fillable: без него форма,
 * в которую дописали лишнее поле, меняла бы что угодно, вплоть до role_id.
 */
abstract class Model implements JsonSerializable
{
    public const CREATED_AT = 'created_at';
    public const UPDATED_AT = 'updated_at';
    public const DELETED_AT = 'deleted_at';

    /** Имя таблицы; пусто — считается по имени класса */
    protected static string $table = '';

    /** Первичный ключ */
    protected static string $primaryKey = 'id';

    /** Проставлять ли created_at и updated_at */
    protected bool $timestamps = true;

    /** Мягкое удаление: запись остаётся, но помечается deleted_at */
    protected bool $softDelete = false;

    /** @var array<int, string> Поля, которые можно заполнять массово */
    protected array $fillable = [];

    /** @var array<int, string> Поля, которые нельзя показывать наружу (toArray, JSON) */
    protected array $hidden = [];

    /** @var array<string, string> Приведение типов: int, float, bool, json, datetime */
    protected array $casts = [];

    /** @var array<string, mixed> Значения колонок */
    protected array $attributes = [];

    /** @var array<string, mixed> Значения, прочитанные из базы, — по ним видно, что изменилось */
    protected array $original = [];

    /** @var array<string, mixed> Загруженные связи */
    protected array $loaded = [];

    /** Запись уже есть в базе */
    protected bool $exists = false;

    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(array $attributes = [])
    {
        $this->fill($attributes);
    }

    // --- Настройки модели ----------------------------------------------------

    /**
     * Имя таблицы: задано свойством или посчитано по имени класса.
     */
    public function table(): string
    {
        if (static::$table !== '') {
            return static::$table;
        }

        $short = substr(strrchr(static::class, '\\') ?: static::class, 1);

        return Str::plural(Str::snake($short));
    }

    public function keyName(): string
    {
        return static::$primaryKey;
    }

    /**
     * Значение первичного ключа.
     */
    public function key(): mixed
    {
        return $this->attributes[static::$primaryKey] ?? null;
    }

    public function id(): int
    {
        return (int) $this->key();
    }

    public function connection(): Connection
    {
        return Connection::instance();
    }

    public function hasTimestamps(): bool
    {
        return $this->timestamps;
    }

    /**
     * Есть ли такая колонка в таблице — нужно массовому обновлению: отметку
     * времени нельзя ставить там, где её не предусмотрели схемой.
     */
    public function usesColumn(string $column): bool
    {
        return $this->hasColumn($column);
    }

    public function softDeletes(): bool
    {
        return $this->softDelete;
    }

    public function existsInDatabase(): bool
    {
        return $this->exists;
    }

    // --- Выборка -------------------------------------------------------------

    /**
     * Построитель запросов этой модели.
     */
    public static function query(): Query
    {
        return new Query(static::class);
    }

    /**
     * @return array<int, static>
     */
    public static function all(): array
    {
        /** @var array<int, static> $models */
        $models = static::query()->get();

        return $models;
    }

    public static function find(int|string|null $id): ?static
    {
        if ($id === null || $id === '' || $id === 0) {
            return null;
        }

        /** @var static|null $model */
        $model = static::query()->where(static::$primaryKey, $id)->first();

        return $model;
    }

    /**
     * Запись обязана быть — иначе исключение, которое ядро превратит в 404.
     */
    public static function findOrFail(int|string|null $id): static
    {
        $model = static::find($id);

        if ($model === null) {
            throw new RecordNotFound('Запись не найдена: ' . static::class . ' #' . (string) $id);
        }

        return $model;
    }

    /**
     * Условие прямо от класса: User::where('active', 1)->get().
     */
    public static function where(string $column, mixed $operator = null, mixed $value = null): Query
    {
        if (func_num_args() === 2) {
            return static::query()->where($column, $operator);
        }

        return static::query()->where($column, $operator, $value);
    }

    /**
     * Первая запись по условию — короткая запись для поиска по одному полю.
     */
    public static function findBy(string $column, mixed $value): ?static
    {
        /** @var static|null $model */
        $model = static::query()->where($column, $value)->first();

        return $model;
    }

    /**
     * Создать и сразу сохранить.
     *
     * @param array<string, mixed> $attributes
     */
    public static function create(array $attributes): static
    {
        $model = new static($attributes);

        $model->save();

        return $model;
    }

    /**
     * Найти по условию или создать новую запись.
     *
     * @param array<string, mixed> $search
     * @param array<string, mixed> $values
     */
    public static function firstOrCreate(array $search, array $values = []): static
    {
        $query = static::query();

        foreach ($search as $column => $value) {
            $query->where($column, $value);
        }

        /** @var static|null $found */
        $found = $query->first();

        if ($found !== null) {
            return $found;
        }

        return static::create(array_merge($search, $values));
    }

    /**
     * Модель из готовой строки базы. Значения считаются сохранёнными.
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): static
    {
        $model = new static();

        $model->attributes = $row;
        $model->original   = $row;
        $model->exists     = true;

        return $model;
    }

    // --- Запись --------------------------------------------------------------

    /**
     * Заполняет разрешённые поля.
     *
     * @param array<string, mixed> $attributes
     */
    public function fill(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            if ($this->fillable === [] || in_array($key, $this->fillable, true)) {
                $this->setAttribute((string) $key, $value);
            }
        }

        return $this;
    }

    /**
     * Заполняет что угодно, минуя белый список. Только для своего кода —
     * данные из запроса сюда пускать нельзя.
     *
     * @param array<string, mixed> $attributes
     */
    public function forceFill(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            $this->setAttribute((string) $key, $value);
        }

        return $this;
    }

    /**
     * Сохраняет: вставляет новую запись или обновляет изменённые поля.
     */
    public function save(): bool
    {
        $now = date('Y-m-d H:i:s');

        if ($this->exists) {
            $changes = $this->changes();

            if ($changes === []) {
                return true;
            }

            if ($this->timestamps && $this->hasColumn(self::UPDATED_AT)) {
                $changes[self::UPDATED_AT] = $this->attributes[self::UPDATED_AT] = $now;
            }

            $this->connection()->update($this->table(), $changes, [static::$primaryKey => $this->key()]);

            $this->original = $this->attributes;

            return true;
        }

        if ($this->timestamps) {
            foreach ([self::CREATED_AT, self::UPDATED_AT] as $column) {
                if (!isset($this->attributes[$column]) && $this->hasColumn($column)) {
                    $this->attributes[$column] = $now;
                }
            }
        }

        $id = $this->connection()->insert($this->table(), $this->forStorage($this->attributes));

        if ($id > 0 && !isset($this->attributes[static::$primaryKey])) {
            $this->attributes[static::$primaryKey] = $id;
        }

        $this->original = $this->attributes;
        $this->exists   = true;

        return true;
    }

    /**
     * Обновить поля и сразу сохранить.
     *
     * @param array<string, mixed> $attributes
     */
    public function update(array $attributes): bool
    {
        return $this->fill($attributes)->save();
    }

    /**
     * Удалить. При включённом мягком удалении запись остаётся с отметкой времени.
     */
    public function delete(): bool
    {
        if (!$this->exists) {
            return false;
        }

        if ($this->softDelete) {
            $this->attributes[self::DELETED_AT] = date('Y-m-d H:i:s');

            $this->connection()->update(
                $this->table(),
                [self::DELETED_AT => $this->attributes[self::DELETED_AT]],
                [static::$primaryKey => $this->key()]
            );

            return true;
        }

        $this->connection()->delete($this->table(), [static::$primaryKey => $this->key()]);

        $this->exists = false;

        return true;
    }

    /**
     * Удалить насовсем, даже если включено мягкое удаление.
     */
    public function forceDelete(): bool
    {
        if (!$this->exists) {
            return false;
        }

        $this->connection()->delete($this->table(), [static::$primaryKey => $this->key()]);

        $this->exists = false;

        return true;
    }

    /**
     * Вернуть мягко удалённую запись.
     */
    public function restore(): bool
    {
        if (!$this->softDelete) {
            return false;
        }

        $this->attributes[self::DELETED_AT] = null;

        $this->connection()->update(
            $this->table(),
            [self::DELETED_AT => null],
            [static::$primaryKey => $this->key()]
        );

        return true;
    }

    public function trashed(): bool
    {
        return $this->softDelete && ($this->attributes[self::DELETED_AT] ?? null) !== null;
    }

    /**
     * Перечитать запись из базы.
     */
    public function refresh(): static
    {
        $fresh = static::find($this->key());

        if ($fresh !== null) {
            $this->attributes = $fresh->attributes;
            $this->original   = $fresh->attributes;
            $this->loaded     = [];
        }

        return $this;
    }

    /**
     * Что изменилось после чтения из базы.
     *
     * @return array<string, mixed>
     */
    public function changes(): array
    {
        $changes = [];

        foreach ($this->attributes as $key => $value) {
            if (!array_key_exists($key, $this->original) || $this->original[$key] !== $value) {
                $changes[$key] = $value;
            }
        }

        // Ключ не меняем никогда: править первичный ключ через save() нельзя
        unset($changes[static::$primaryKey]);

        return $this->forStorage($changes);
    }

    public function isDirty(): bool
    {
        return $this->changes() !== [];
    }

    // --- Связи ---------------------------------------------------------------

    /**
     * Много записей чужой таблицы ссылаются на нас.
     *
     * @param class-string<Model> $related
     */
    public function hasMany(string $related, ?string $foreignKey = null, ?string $localKey = null): HasMany
    {
        return new HasMany($this, $related, $foreignKey ?? $this->foreignKeyName(), $localKey ?? static::$primaryKey);
    }

    /**
     * Одна чужая запись ссылается на нас.
     *
     * @param class-string<Model> $related
     */
    public function hasOne(string $related, ?string $foreignKey = null, ?string $localKey = null): HasOne
    {
        return new HasOne($this, $related, $foreignKey ?? $this->foreignKeyName(), $localKey ?? static::$primaryKey);
    }

    /**
     * Мы ссылаемся на чужую запись.
     *
     * @param class-string<Model> $related
     */
    public function belongsTo(string $related, ?string $foreignKey = null, ?string $ownerKey = null): BelongsTo
    {
        $instance = new $related();

        return new BelongsTo(
            $this,
            $related,
            $foreignKey ?? $instance->foreignKeyName(),
            $ownerKey ?? $instance->keyName()
        );
    }

    /**
     * Многие ко многим через таблицу-связку.
     *
     * @param class-string<Model> $related
     */
    public function belongsToMany(
        string $related,
        ?string $pivot = null,
        ?string $foreignKey = null,
        ?string $relatedKey = null
    ): BelongsToMany {
        $instance = new $related();

        // Имя связки по умолчанию — обе таблицы через подчёркивание по алфавиту
        if ($pivot === null) {
            $tables = [$this->table(), $instance->table()];

            sort($tables);

            $pivot = implode('_', $tables);
        }

        return new BelongsToMany(
            $this,
            $related,
            $pivot,
            $foreignKey ?? $this->foreignKeyName(),
            $relatedKey ?? $instance->foreignKeyName()
        );
    }

    /**
     * Имя внешнего ключа на эту модель: User -> user_id.
     */
    public function foreignKeyName(): string
    {
        $short = substr(strrchr(static::class, '\\') ?: static::class, 1);

        return Str::snake($short) . '_id';
    }

    /**
     * Объект связи по имени метода — нужен подгрузке через with().
     */
    public function relation(string $name): Relation
    {
        if (!method_exists($this, $name)) {
            throw new ProtonException('У модели ' . static::class . ' нет связи «' . $name . '»');
        }

        $relation = $this->{$name}();

        if (!$relation instanceof Relation) {
            throw new ProtonException('Метод ' . $name . '() модели ' . static::class . ' не возвращает связь');
        }

        return $relation;
    }

    /**
     * Кладёт готовое значение связи — так работает подгрузка через with().
     */
    public function setRelation(string $name, mixed $value): void
    {
        $this->loaded[$name] = $value;
    }

    public function relationLoaded(string $name): bool
    {
        return array_key_exists($name, $this->loaded);
    }

    // --- Доступ к значениям --------------------------------------------------

    public function __get(string $name): mixed
    {
        if (array_key_exists($name, $this->loaded)) {
            return $this->loaded[$name];
        }

        if (array_key_exists($name, $this->attributes)) {
            return $this->castValue($name, $this->attributes[$name]);
        }

        // Связь, объявленная методом: $user->posts вернёт результат, $user->posts() — саму связь
        if (method_exists($this, $name)) {
            $relation = $this->{$name}();

            if ($relation instanceof Relation) {
                return $this->loaded[$name] = $relation->result();
            }
        }

        return null;
    }

    public function __set(string $name, mixed $value): void
    {
        $this->setAttribute($name, $value);
    }

    public function __isset(string $name): bool
    {
        return isset($this->attributes[$name]) || isset($this->loaded[$name]);
    }

    public function setAttribute(string $name, mixed $value): void
    {
        $this->attributes[$name] = $value;
    }

    /**
     * Значение как оно лежит в базе, без приведения типов.
     */
    public function raw(string $name): mixed
    {
        return $this->attributes[$name] ?? null;
    }

    /**
     * Все значения как массив — с приведением типов и без скрытых полей.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = [];

        foreach ($this->attributes as $key => $value) {
            if (in_array($key, $this->hidden, true)) {
                continue;
            }

            $result[$key] = $this->castValue((string) $key, $value);
        }

        foreach ($this->loaded as $key => $value) {
            if (is_array($value)) {
                $result[$key] = array_map(
                    static fn (mixed $item): mixed => $item instanceof self ? $item->toArray() : $item,
                    $value
                );

                continue;
            }

            $result[$key] = $value instanceof self ? $value->toArray() : $value;
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Приведение значения по объявленному типу.
     */
    protected function castValue(string $name, mixed $value): mixed
    {
        $type = $this->casts[$name] ?? null;

        if ($type === null || $value === null) {
            return $value;
        }

        return match ($type) {
            'int', 'integer' => (int) $value,
            'float', 'double' => (float) $value,
            'bool', 'boolean' => (bool) $value && $value !== '0',
            'json', 'array'   => is_array($value) ? $value : (json_decode((string) $value, true) ?? []),
            'datetime'        => is_string($value) ? $value : (string) $value,
            default           => $value,
        };
    }

    /**
     * Готовит значения к записи: массивы и объекты уезжают в базу как JSON,
     * булево — числом. Иначе PDO спотыкается о тип, которого не знает.
     *
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    protected function forStorage(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $values[$key] = (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                continue;
            }

            if (is_bool($value)) {
                $values[$key] = (int) $value;
            }
        }

        return $values;
    }

    /**
     * Есть ли колонка в таблице. Ответ на процесс один: спрашивать схему на
     * каждое сохранение незачем.
     */
    protected function hasColumn(string $column): bool
    {
        static $columns = [];

        $table = $this->table();

        if (!isset($columns[$table])) {
            $columns[$table] = [];
        }

        if (!array_key_exists($column, $columns[$table])) {
            $columns[$table][$column] = $this->connection()->hasColumn($table, $column);
        }

        return $columns[$table][$column];
    }
}
