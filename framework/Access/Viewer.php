<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Access;

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

    /**
     * @param array<int, string> $permissions
     */
    private function __construct(int $id, string $login, string $name, array $permissions, bool $full, ?User $user = null)
    {
        $this->id          = $id;
        $this->login       = $login;
        $this->name        = $name;
        $this->permissions = $permissions;
        $this->full        = $full;
        $this->user        = $user;
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
