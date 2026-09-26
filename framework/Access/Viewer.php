<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Access;

use Rsgrinko\Proton\Models\ApiToken;
use Rsgrinko\Proton\Models\User;

/**
 * Кто смотрит: пользователь, его права и область видимости.
 *
 * Объект собирает прослойка auth и кладёт в атрибуты запроса, а роутер подставляет
 * его в методы контроллеров по имени аргумента — контроллеру не нужно знать
 * ни про сессию, ни про то, как считаются права.
 */
final class Viewer
{
    private int $id;

    private string $login;

    private string $name;

    /** @var array<int, string> */
    private array $permissions;

    /** Доступ без ограничений: консоль, воркер, тесты */
    private bool $full;

    private ?User $user;

    /** Встроенная роль администратора: раздавать может всё, что есть и будет */
    private bool $superuser;

    /**
     * @param array<int, string> $permissions
     */
    private function __construct(int $id, string $login, string $name, array $permissions, bool $full, ?User $user = null, bool $superuser = false)
    {
        $this->id          = $id;
        $this->login       = $login;
        $this->name        = $name;
        $this->permissions = $permissions;
        $this->full        = $full;
        $this->user        = $user;
        $this->superuser   = $superuser;
    }

    /**
     * Полный доступ — когда спрашивать некого: консоль, воркер, разовые задачи.
     */
    public static function full(): self
    {
        return new self(0, 'system', 'система', Permission::all(), true);
    }

    /**
     * Гость: не вошёл, прав нет.
     */
    public static function guest(): self
    {
        return new self(0, '', 'гость', [], false);
    }

    public static function fromUser(User $user): self
    {
        return new self(
            $user->id(),
            (string) $user->login,
            (string) ($user->name !== '' ? $user->name : $user->login),
            $user->permissions(),
            false,
            $user,
            $user->isSuperuser()
        );
    }

    /**
     * Тот же пользователь, но через ключ API: ключ со скоупом урезает права
     * до пересечения с тем, что ему разрешили при выпуске, — даже если у
     * владельца их больше. Пустой скоуп у ключа ничего не урезает.
     */
    public static function forToken(User $user, ApiToken $token): self
    {
        $permissions = $user->permissions();
        $abilities   = $token->abilities();

        if ($abilities !== []) {
            $permissions = array_values(array_intersect($permissions, $abilities));
        }

        return new self(
            $user->id(),
            (string) $user->login,
            (string) ($user->name !== '' ? $user->name : $user->login),
            $permissions,
            false,
            $user
        );
    }

    public function id(): int
    {
        return $this->id;
    }

    public function login(): string
    {
        return $this->login;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function user(): ?User
    {
        return $this->user;
    }

    public function isGuest(): bool
    {
        return $this->id === 0 && !$this->full;
    }

    public function can(string $permission): bool
    {
        return $this->full || in_array($permission, $this->permissions, true);
    }

    /**
     * Хватит любого права из списка — для разделов, куда пускают и на просмотр,
     * и на правку.
     *
     * @param array<int, string> $permissions
     */
    public function canAny(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->can($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Есть ли у него все эти права — значит, он вправе их раздать.
     *
     * На этом держится правило «нельзя выдать больше своего»: без него
     * users.manage назначал бы себе роль администратора, roles.manage дописывал
     * своей роли любое право, а users.impersonate входил под администратором.
     * Распоряжаться человеком (править, удалять, входить под ним, выпускать
     * ему ключ) можно, только если его права целиком покрыты своими:
     * $viewer->covers($user->permissions()).
     *
     * @param array<int, string> $permissions
     */
    public function covers(array $permissions): bool
    {
        // Администратор со встроенной ролью покрывает всё — и то право, что
        // зарегистрировали уже после того, как его права посчитали
        return $this->full || $this->superuser || array_diff($permissions, $this->permissions) === [];
    }

    /**
     * Видит ли чужие записи.
     */
    public function isAdmin(): bool
    {
        return $this->can(Permission::DATA_ALL);
    }

    public function scope(): Scope
    {
        return $this->isAdmin() ? Scope::all() : Scope::owner($this->id);
    }

    /**
     * @return array<int, string>
     */
    public function permissions(): array
    {
        return $this->permissions;
    }
}
