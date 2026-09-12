<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Console\Commands;

use Rsgrinko\Proton\Console\Command;
use Rsgrinko\Proton\Models\User;

/**
 * удалить пользователя.
 */
final class UserDeleteCommand extends Command
{
    public function name(): string
    {
        return 'user:delete';
    }

    public function description(): string
    {
        return 'удалить пользователя';
    }

    public function usage(): string
    {
        return 'user:delete <логин> [--force]';
    }

    public function run(): int
    {
        $login = trim($this->arg(0));
        $user  = User::findBy('login', $login);

        if ($user === null) {
            $this->fail('Пользователь не найден: ' . $login);

            return 1;
        }

        // Последнего активного не удаляем: войти станет некем, а страница
        // первого запуска откроется всем подряд
        if (User::query()->where('active', 1)->count() <= 1 && $user->isActive()) {
            $this->fail('Это последний активный пользователь — удалять его нельзя');

            return 1;
        }

        if (!$this->confirm('Удалить пользователя ' . $login . '?')) {
            $this->line('Отменено');

            return 1;
        }

        $user->logoutEverywhere();
        $user->delete();

        $this->ok('Пользователь удалён');

        return 0;
    }
}
