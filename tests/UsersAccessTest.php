<?php

declare(strict_types=1);

/**
 * Доступ к разделу «Пользователи»: users.view пускает смотреть, users.manage
 * нужен отдельно на каждый мутирующий маршрут.
 */

use Rsgrinko\Proton\Models\Role;
use Rsgrinko\Proton\Models\User;

/**
 * Пользователь с ролью из одних только переданных прав.
 */
function usersAccessTestUser(array $permissions): User
{
    $role = Role::create([
        'name'        => 'Доступ к пользователям ' . bin2hex(random_bytes(3)),
        'permissions' => $permissions,
    ]);

    $user = User::register('users_access_' . bin2hex(random_bytes(3)), 'секрет123', [
        'email'   => 'users_access' . bin2hex(random_bytes(3)) . '@example.com',
        'role_id' => $role->id(),
    ]);

    afterTests(static function () use ($user, $role): void {
        $user->forceDelete();
        $role->forceDelete();
    });

    return $user;
}

test('права: users.view пускает смотреть список и карточку, но не заводить и не менять', function (): void {
    $viewer = usersAccessTestUser(['users.view']);
    $target = usersAccessTestUser(['users.view']);

    assertStatus(200, httpRequest('GET', '/admin/users', $viewer));
    assertStatus(200, httpRequest('GET', '/admin/users/' . $target->id(), $viewer));

    assertStatus(403, httpRequest('GET', '/admin/users/new', $viewer));
    assertStatus(403, httpRequest('POST', '/admin/users/new', $viewer, ['login' => 'x']));
    assertStatus(403, httpRequest('POST', '/admin/users/' . $target->id(), $viewer, ['name' => 'Новое имя']));
    assertStatus(403, httpRequest('POST', '/admin/users/' . $target->id() . '/delete', $viewer));
    assertStatus(403, httpRequest('POST', '/admin/users/bulk', $viewer, ['ids' => [$target->id()], 'action' => 'disable']));
});

test('права: users.manage по-прежнему даёт доступ и к списку, и к правке', function (): void {
    $manager = usersAccessTestUser(['users.manage']);
    $target  = usersAccessTestUser(['users.view']);

    assertStatus(200, httpRequest('GET', '/admin/users', $manager));
    assertStatus(200, httpRequest('GET', '/admin/users/new', $manager));
    assertStatus(200, httpRequest('GET', '/admin/users/' . $target->id(), $manager));
});

test('права: без users.view и users.manage раздел закрыт совсем', function (): void {
    $stranger = usersAccessTestUser([]);

    assertStatus(403, httpRequest('GET', '/admin/users', $stranger));
});
