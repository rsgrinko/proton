<?php

declare(strict_types=1);

/**
 * Фабрики моделей: из чего делать записи в тестах и сидерах.
 *
 * Описание — функция, получающая порядковый номер вызова: им отличаются логины
 * и адреса, иначе вторая запись упрётся в уникальный индекс.
 *
 *     Factory::of(Note::class)->times(5)->create(['user_id' => $user->id()]);
 */

use App\Models\Note;
use Rsgrinko\Proton\Auth\Password;
use Rsgrinko\Proton\Database\Model\Factory;
use Rsgrinko\Proton\Models\Role;
use Rsgrinko\Proton\Models\User;

Factory::define(User::class, static fn (int $number): array => [
    'login'         => 'user_' . Factory::unique(),
    'email'         => Factory::unique('user') . '@example.com',
    'name'          => 'Пользователь ' . $number,
    'role_id'       => Role::query()->where('is_system', 0)->value('id') ?? 0,
    'active'        => 1,
    // Пароль кладём хешем: фабрика не должна знать, как он считается,
    // но и сырую строку в базу класть нельзя
    'password_hash' => Password::hash(Factory::unique('pass')),
]);

Factory::define(Note::class, static fn (int $number): array => [
    'title'  => 'Заметка ' . $number . ': ' . Factory::sentence(3),
    'body'   => Factory::sentence(20),
    'pinned' => 0,
]);
