<?php

declare(strict_types=1);

/**
 * Нельзя выдать больше своего: users.manage, roles.manage, users.impersonate и
 * tokens.manage не должны становиться дорогой к правам администратора.
 */

use Rsgrinko\Proton\Auth\Auth;
use Rsgrinko\Proton\Models\ApiToken;
use Rsgrinko\Proton\Models\Role;
use Rsgrinko\Proton\Models\User;

/**
 * Пользователь с ролью из одних только переданных прав.
 */
function grantTestUser(array $permissions): User
{
    $role = Role::create([
        'name'        => 'Выдача прав ' . bin2hex(random_bytes(3)),
        'permissions' => $permissions,
    ]);

    $user = User::register('grant_' . bin2hex(random_bytes(3)), 'секрет123', [
        'email'   => 'grant' . bin2hex(random_bytes(3)) . '@example.com',
        'role_id' => $role->id(),
    ]);

    afterTests(static function () use ($user, $role): void {
        ApiToken::query()->where('user_id', $user->id())->forceDelete();
        $user->forceDelete();
        $role->forceDelete();
    });

    return $user;
}

/**
 * Администратор со встроенной ролью.
 */
function grantTestAdmin(): User
{
    $admin = User::register('grant_admin_' . bin2hex(random_bytes(3)), 'секрет123', [
        'email'   => 'grant_admin' . bin2hex(random_bytes(3)) . '@example.com',
        'role_id' => Role::admin()?->id() ?? 0,
    ]);

    afterTests(static function () use ($admin): void {
        ApiToken::query()->where('user_id', $admin->id())->forceDelete();
        $admin->forceDelete();
    });

    return $admin;
}

test('выдача прав: users.manage не поднимает себе роль до администратора', function (): void {
    $manager = grantTestUser(['users.manage']);
    $roleId  = (int) $manager->raw('role_id');

    $response = httpRequest('POST', '/admin/users/' . $manager->id(), $manager, [
        'role_id' => Role::admin()?->id(),
        'active'  => 1,
    ]);

    assertStatus(403, $response);
    assertSame($roleId, (int) User::find($manager->id())?->raw('role_id'), 'роль не должна была смениться');

    // Свою же роль оставить при правке можно — она покрыта собственными правами
    assertStatus(302, httpRequest('POST', '/admin/users/' . $manager->id(), $manager, [
        'role_id' => $roleId,
        'name'    => 'Новое имя',
        'active'  => 1,
    ]));
});

test('выдача прав: users.manage не заводит администратора', function (): void {
    $manager = grantTestUser(['users.manage']);
    $login   = 'grant_new_' . bin2hex(random_bytes(3));

    assertStatus(403, httpRequest('POST', '/admin/users/new', $manager, [
        'login'   => $login,
        'role_id' => Role::admin()?->id(),
    ]));

    assertTrue(User::query()->where('login', $login)->first() === null, 'пользователь не должен был завестись');
});

test('выдача прав: того, у кого прав больше, не правят и не удаляют', function (): void {
    $manager = grantTestUser(['users.manage']);
    $admin   = grantTestAdmin();

    assertStatus(403, httpRequest('POST', '/admin/users/' . $admin->id(), $manager, [
        'role_id'  => Role::admin()?->id(),
        'password' => 'чужойпароль123',
        'active'   => 1,
    ]));
    assertStatus(403, httpRequest('POST', '/admin/users/' . $admin->id() . '/delete', $manager));

    assertTrue(User::find($admin->id()) !== null, 'администратор на месте');
    assertTrue(User::find($admin->id())?->verifyPassword('секрет123') === true, 'пароль администратора не сменился');

    // Массовое действие его тоже молча обходит
    httpRequest('POST', '/admin/users/bulk', $manager, ['ids' => [$admin->id()], 'action' => 'disable']);

    assertTrue(User::find($admin->id())?->isActive() === true, 'администратор не выключен');
});

test('выдача прав: под тем, у кого прав больше, не входят', function (): void {
    $manager = grantTestUser(['users.view', 'users.impersonate']);
    $admin   = grantTestAdmin();
    $plain   = grantTestUser([]);

    try {
        assertStatus(403, httpRequest('POST', '/admin/users/' . $admin->id() . '/impersonate', $manager));
        assertFalse(Auth::isImpersonating(), 'под администратором не вошли');

        assertStatus(302, httpRequest('POST', '/admin/users/' . $plain->id() . '/impersonate', $manager));
        assertTrue(Auth::isImpersonating(), 'под тем, у кого прав меньше, — можно');
    } finally {
        Auth::stopImpersonating();
    }
});

test('выдача прав: roles.manage раздаёт только свои права', function (): void {
    $manager = grantTestUser(['roles.view', 'roles.manage', 'notes.view']);
    $name    = 'Роль с лишним ' . bin2hex(random_bytes(3));

    assertStatus(403, httpRequest('POST', '/admin/roles/new', $manager, [
        'name'        => $name,
        'permissions' => ['notes.view', 'system.manage'],
    ]));
    assertTrue(Role::query()->where('name', $name)->first() === null, 'роль не должна была завестись');

    // Своей роли дописать чужое право тоже нельзя
    $own = (int) $manager->raw('role_id');

    assertStatus(403, httpRequest('POST', '/admin/roles/' . $own, $manager, [
        'name'        => 'Своя',
        'permissions' => ['roles.view', 'roles.manage', 'notes.view', 'users.manage'],
    ]));
    assertFalse(in_array('users.manage', Role::find($own)?->permissions() ?? [], true), 'право не добавилось');

    // Встроенную роль администратора он не правит вовсе
    assertStatus(403, httpRequest('POST', '/admin/roles/' . Role::admin()?->id(), $manager, ['name' => 'Переименовал']));

    // А из своих прав собрать роль можно
    $ok = httpRequest('POST', '/admin/roles/new', $manager, [
        'name'        => $name,
        'permissions' => ['notes.view'],
    ]);

    assertStatus(302, $ok);

    Role::query()->where('name', $name)->forceDelete();
});

test('выдача прав: tokens.manage не выпускает ключ на администратора', function (): void {
    $manager = grantTestUser(['tokens.view', 'tokens.manage']);
    $admin   = grantTestAdmin();

    assertStatus(403, httpRequest('POST', '/admin/tokens', $manager, ['user_id' => $admin->id(), 'name' => 'чужой']));
    assertSame(0, ApiToken::query()->where('user_id', $admin->id())->count());

    assertStatus(302, httpRequest('POST', '/admin/tokens', $manager, ['user_id' => $manager->id(), 'name' => 'свой']));
    assertSame(1, ApiToken::query()->where('user_id', $manager->id())->count());
});
