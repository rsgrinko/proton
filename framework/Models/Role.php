<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Models;

use Rsgrinko\Proton\Access\Permission;
use Rsgrinko\Proton\Access\Viewer;
use Rsgrinko\Proton\Database\Model\Model;
use Rsgrinko\Proton\Database\Model\Relations\HasMany;
use Rsgrinko\Proton\Support\Config;

/**
 * Роль — набор прав под именем. Пользователю выдаётся одна роль, своих галочек
 * у него нет: поменяли роль — поменялось у всех, кому она выдана.
 */
final class Role extends Model
{
    protected static string $table = 'roles';

    // is_system сюда не входит: встроенность роли — это права администратора,
    // её ставят миграция и код через forceFill(), а не форма
    protected array $fillable = ['name', 'description', 'permissions'];

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
     * Роли, которые этот человек вправе выдать: только те, чьи права целиком
     * есть у него самого (см. Viewer::covers()). Этим списком заполняется
     * выбор роли в карточке пользователя, и по нему же проверяется присланное.
     *
     * @return array<int, self>
     */
    public static function assignableBy(Viewer $viewer): array
    {
        /** @var array<int, self> $roles */
        $roles = self::query()->orderBy('id')->get();

        return array_values(array_filter(
            $roles,
            static fn (self $role): bool => $viewer->covers($role->permissions())
        ));
    }

    /**
     * Роль для тех, кто зарегистрировался сам (настройка AUTH_REGISTRATION_ROLE),
     * или null, если выдавать нечего — тогда регистрация закрыта.
     *
     * Роль называется явно, а не «первая невстроенная»: иначе стоило удалить
     * «Пользователя», и новички получали бы следующую по порядку — хоть с
     * users.manage. По той же причине роль с правами на управление сервисом
     * (всё, чего нет в Permission::user()) не выдаётся вовсе.
     */
    public static function forRegistration(): ?self
    {
        return self::registrationProblem() === '' ? self::registrationRole() : null;
    }

    /**
     * Что не так с ролью для регистрации; пусто — всё в порядке. Нужна
     * состоянию сервиса и логу: закрытая регистрация без причины выглядит
     * поломкой.
     */
    public static function registrationProblem(): string
    {
        $name = trim((string) Config::get('auth.registration_role', ''));

        if ($name === '') {
            return 'не задана AUTH_REGISTRATION_ROLE';
        }

        $role = self::registrationRole();

        if ($role === null) {
            return 'роли «' . $name . '» нет';
        }

        $extra = array_diff($role->permissions(), Permission::user());

        if ($role->isSystem() || $extra !== []) {
            return 'у роли «' . $name . '» права на управление: ' . implode(', ', $role->isSystem() ? ['все'] : $extra);
        }

        return '';
    }

    private static function registrationRole(): ?self
    {
        /** @var self|null $role */
        $role = self::query()->where('name', trim((string) Config::get('auth.registration_role', '')))->first();

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
