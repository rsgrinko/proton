<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Console\Commands;

use Rsgrinko\Proton\Auth\Password;
use Rsgrinko\Proton\Console\Command;
use Rsgrinko\Proton\Models\Role;
use Rsgrinko\Proton\Models\User;
use Rsgrinko\Proton\Support\Validator;

/**
 * завести пользователя.
 */
final class UserCreateCommand extends Command
{
    public function name(): string
    {
        return 'user:create';
    }

    public function description(): string
    {
        return 'завести пользователя';
    }

    public function usage(): string
    {
        return 'user:create <логин> [--password=] [--email=] [--name=] [--role=]';
    }

    public function run(): int
    {
        $login = trim($this->arg(0));

        if ($login === '') {
            $this->fail('Укажите логин: php bin/proton user:create ivan');

            return 1;
        }

        if (User::findBy('login', $login) !== null) {
            $this->fail('Пользователь с таким логином уже есть');

            return 1;
        }

        $email = (string) $this->option('email', '');

        if ($email !== '' && !Validator::isEmail($email)) {
            $this->fail('Неверный адрес почты: ' . $email);

            return 1;
        }

        // Пароль не спрашиваем в открытую в неинтерактивном запуске: сгенерируем
        // и напечатаем один раз — в базе он всё равно только хешем
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

        $role = $this->role();

        $user = User::register($login, $password, [
            'email'   => $email,
            'name'    => (string) $this->option('name', $login),
            'role_id' => $role?->id() ?? 0,
        ]);

        $this->ok('Пользователь ' . $user->login . ' заведён' . ($role !== null ? ', роль «' . $role->name . '»' : ''));

        if ($generated) {
            $this->line('  Пароль: ' . $password);
            $this->line('  Он больше нигде не сохранён — запишите сейчас');
        }

        return 0;
    }

    private function role(): ?Role
    {
        $name = (string) $this->option('role', '');

        if ($name !== '') {
            return Role::findBy('name', $name);
        }

        // Роль не указали: первому пользователю даём администратора,
        // остальным — ту же, что получают при регистрации (AUTH_REGISTRATION_ROLE),
        // а не «первую невстроенную»: та может оказаться с правами управления
        if (!User::query()->exists()) {
            return Role::admin();
        }

        return Role::forRegistration();
    }
}
