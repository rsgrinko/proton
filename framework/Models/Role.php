<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Models;

use Rsgrinko\Proton\Access\Permission;
use Rsgrinko\Proton\Database\Model\Model;
use Rsgrinko\Proton\Database\Model\Relations\HasMany;

/**
 * Роль — набор прав под именем. Пользователю выдаётся одна роль, своих галочек
 * у него нет: поменяли роль — поменялось у всех, кому она выдана.
 */
final class Role extends Model
{
    protected static string $table = 'roles';

    protected array $fillable = ['name', 'description', 'permissions', 'is_system'];

    protected array $casts = [
        'permissions' => 'json',
        'is_system'   => 'bool',
    ];

    /** Мягкое удаление: роль возвращается из корзины вместе с правами */
    protected bool $softDelete = true;

    /**
     * Права роли.
     *
     * У встроенной роли администратора список берётся из кода, а не из базы:
     * права растут вместе с разделами, и записанный когда-то набор оставил бы
     * администратора без нового раздела.
     *
     * @return array<int, string>
     */
    public function permissions(): array
    {
        if ($this->isSystem()) {
            return Permission::admin();
        }

        return Permission::filter((array) ($this->permissions ?? []));
    }

    public function isSystem(): bool
    {
        return (int) $this->raw('is_system') === 1;
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'role_id');
    }

    /**
     * Встроенная роль администратора. Ищется по признаку встроенности, а не по
     * имени: имя правится в панели.
     */
    public static function admin(): ?self
    {
        /** @var self|null $role */
        $role = self::query()->where('is_system', 1)->first();

        return $role;
    }

    /**
     * Сколько человек с этой ролью — карточка роли показывает это перед удалением.
     */
    public function usersCount(): int
    {
        return User::query()->where('role_id', $this->id())->count();
    }
}
