<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Console\Commands;

use Rsgrinko\Proton\Auth\Password;
use Rsgrinko\Proton\Console\Command;
use Rsgrinko\Proton\Models\User;

/**
 * сменить пароль.
 */
final class UserPasswordCommand extends Command
{
    public function name(): string
    {
        return 'user:password';
    }

    public function description(): string
    {
        return 'сменить пароль пользователю';
    }

    public function usage(): string
    {
        return 'user:password <логин> [--password=]';
    }

    public function run(): int
    {
        $login = trim($this->arg(0));
        $user  = User::findBy('login', $login);

        if ($user === null) {
            $this->fail('Пользователь не найден: ' . $login);

            return 1;
        }

        $password  = (string) $this->option('password', '');
        $generated = $password === '';

        if ($generated) {
            $password = Password::generate();
        }

        $error = Password::check($password);

        if ($error !== null) {
            $this->fail($error);

            return 1;
        }

        // setPassword заодно гасит все сеансы: пароль меняют, когда его увели
        $user->setPassword($password);
        $user->save();

        $this->ok('Пароль изменён, все сеансы завершены');

        if ($generated) {
            $this->line('  Новый пароль: ' . $password);
        }

        return 0;
    }
}
