<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Console\Commands;

use Rsgrinko\Proton\Console\Command;
use Rsgrinko\Proton\Models\Role;
use Rsgrinko\Proton\Models\User;

/**
 * список пользователей.
 */
final class UserListCommand extends Command
{
    public function name(): string
    {
        return 'user:list';
    }

    public function description(): string
    {
        return 'список пользователей';
    }

    public function run(): int
    {
        /** @var array<int, User> $users */
        $users = User::query()->orderBy('id')->get();

        if ($users === []) {
            $this->line('Пользователей нет. Заведите первого: php bin/proton user:create ivan');

            return 0;
        }

        $roles = [];

        foreach (Role::all() as $role) {
            $roles[$role->id()] = (string) $role->name;
        }

        $rows = [];

        foreach ($users as $user) {
            $rows[] = [
                (string) $user->id(),
                (string) $user->login,
                (string) $user->email,
                $roles[(int) $user->raw('role_id')] ?? '—',
                $user->isActive() ? 'да' : 'нет',
                (string) ($user->raw('last_login_at') ?? '—'),
            ];
        }

        $this->table(['id', 'Логин', 'Почта', 'Роль', 'Активен', 'Последний вход'], $rows);

        return 0;
    }
}
