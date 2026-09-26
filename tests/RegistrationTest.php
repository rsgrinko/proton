<?php

declare(strict_types=1);

/**
 * Роль для тех, кто регистрируется сам: задаётся явно и не бывает с правами
 * на управление сервисом.
 */

use Rsgrinko\Proton\Models\Role;
use Rsgrinko\Proton\Models\User;

/**
 * Отправляет форму регистрации и возвращает заведённого (или null).
 */
function registrationTestSubmit(): ?User
{
    // Пока в базе нет ни одного пользователя, всё уводит в установщик
    httpAdmin();

    $login = 'reg_' . bin2hex(random_bytes(3));

    httpRequest('POST', '/register', null, [
        'login'                 => $login,
        'email'                 => $login . '@example.com',
        'password'              => 'секрет123',
        'password_confirmation' => 'секрет123',
    ]);

    /** @var User|null $user */
    $user = User::query()->where('login', $login)->first();

    if ($user !== null) {
        afterTests(static fn () => $user->forceDelete());
    }

    return $user;
}

test('регистрация: новичок получает роль из настройки', function (): void {
    $role = Role::create(['name' => 'Новички ' . bin2hex(random_bytes(3)), 'permissions' => ['notes.view']]);

    afterTests(static fn () => $role->forceDelete());

    withConfig(['auth.registration' => true, 'auth.registration_role' => (string) $role->name], static function () use ($role): void {
        $user = assertNotNull(registrationTestSubmit(), 'регистрация должна пройти');

        assertSame($role->id(), (int) $user->raw('role_id'));
    });
});

test('регистрация: роль с правами на управление не выдаётся — регистрация закрыта', function (): void {
    $role = Role::create(['name' => 'Слишком много ' . bin2hex(random_bytes(3)), 'permissions' => ['notes.view', 'users.manage']]);

    afterTests(static fn () => $role->forceDelete());

    withConfig(['auth.registration' => true, 'auth.registration_role' => (string) $role->name], static function (): void {
        assertContains('users.manage', Role::registrationProblem());
        assertNull(registrationTestSubmit(), 'с такой ролью никого не заводим');
    });

    // Встроенная администраторская — тем более
    withConfig(['auth.registration' => true, 'auth.registration_role' => (string) Role::admin()?->name], static function (): void {
        assertNull(registrationTestSubmit());
    });
});

test('регистрация: удалённая роль не подменяется первой попавшейся', function (): void {
    withConfig(['auth.registration' => true, 'auth.registration_role' => 'Такой роли нет'], static function (): void {
        assertContains('нет', Role::registrationProblem());
        assertNull(registrationTestSubmit());
    });
});
