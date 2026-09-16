<?php

declare(strict_types=1);

/**
 * Вход под пользователем: Auth::impersonate()/stopImpersonating()/realUser().
 *
 * Тестовый помощник httpRequest() подставляет пользователя через Auth::actAs(),
 * минуя сессию совсем, — этим не проверить то, что подмена как раз и держит
 * в сессии между запросами. Поэтому здесь настоящий Auth::login(), как в
 * браузере, а не короткий путь остальных тестов.
 */

use Rsgrinko\Proton\Auth\Auth;
use Rsgrinko\Proton\Models\Role;
use Rsgrinko\Proton\Models\User;

/**
 * Два настоящих пользователя для сеанса подмены — заводим один раз на файл.
 *
 * @return array{admin: User, target: User}
 */
function impersonateTestUsers(): array
{
    static $users = null;

    if ($users !== null) {
        return $users;
    }

    $admin = User::register('impersonate_admin_' . bin2hex(random_bytes(3)), 'секрет123', [
        'email'   => 'imp_admin' . bin2hex(random_bytes(3)) . '@example.com',
        'role_id' => Role::admin()?->id() ?? 0,
    ]);

    $target = User::register('impersonate_target_' . bin2hex(random_bytes(3)), 'секрет123', [
        'email'   => 'imp_target' . bin2hex(random_bytes(3)) . '@example.com',
        'role_id' => Role::admin()?->id() ?? 0,
    ]);

    // register() задаёт пароль через setPassword(), а тот заодно ставит
    // sessions_from = сейчас (гасит чужие сеансы после смены пароля). Внутри
    // теста регистрация и вход идут в одну секунду, и Auth::sessionAlive()
    // посчитал бы свежий вход уже погашенным — сбрасываем отметку, как будто
    // между регистрацией и входом прошло время
    $admin->forceFill(['sessions_from' => null])->save();
    $target->forceFill(['sessions_from' => null])->save();

    $users = ['admin' => $admin, 'target' => $target];

    afterTests(static function () use ($admin, $target): void {
        Auth::forget();
        $admin->forceDelete();
        $target->forceDelete();
    });

    return $users;
}

test('подмена: user() отдаёт цель, realUser() — настоящего вошедшего', function (): void {
    ['admin' => $admin, 'target' => $target] = impersonateTestUsers();

    Auth::login($admin);

    try {
        assertFalse(Auth::isImpersonating());
        assertSame($admin->id(), Auth::user()?->id());
        assertSame($admin->id(), Auth::realUser()?->id());

        assertTrue(Auth::impersonate($target));

        // Auth::impersonate() сбрасывает только кэш эффективного пользователя —
        // как после save() модели, здесь ничего заново читать руками не нужно
        assertTrue(Auth::isImpersonating());
        assertSame($target->id(), Auth::user()?->id(), 'user() теперь отдаёт цель');
        assertSame($admin->id(), Auth::realUser()?->id(), 'realUser() остаётся настоящим вошедшим');

        Auth::stopImpersonating();

        assertFalse(Auth::isImpersonating());
        assertSame($admin->id(), Auth::user()?->id(), 'после остановки — снова собой');
    } finally {
        Auth::logout();
    }
});

test('подмена: входить под собой нельзя', function (): void {
    ['admin' => $admin] = impersonateTestUsers();

    Auth::login($admin);

    try {
        assertFalse(Auth::impersonate($admin));
        assertFalse(Auth::isImpersonating());
    } finally {
        Auth::logout();
    }
});

test('подмена: если цель отключили, следующий user() тихо возвращает настоящего', function (): void {
    ['admin' => $admin, 'target' => $target] = impersonateTestUsers();

    Auth::login($admin);

    try {
        Auth::impersonate($target);
        assertSame($target->id(), Auth::user()?->id());

        $target->forceFill(['active' => 0])->save();

        // user() кэширует ответ на процесс — в веб-приложении это следующий
        // HTTP-запрос, здесь тот же эффект даёт forget(): «начать читать заново»
        Auth::forget();

        assertSame($admin->id(), Auth::user()?->id(), 'отключённую цель user() сам сбрасывает');
        assertFalse(Auth::isImpersonating(), 'а флаг подмены сброшен, а не завис');
    } finally {
        $target->forceFill(['active' => 1])->save();
        Auth::stopImpersonating();
        Auth::logout();
    }
});

test('подмена: logout() гасит и саму подмену', function (): void {
    ['admin' => $admin, 'target' => $target] = impersonateTestUsers();

    Auth::login($admin);
    Auth::impersonate($target);

    assertTrue(Auth::isImpersonating());

    Auth::logout();

    assertFalse(Auth::isImpersonating());
    assertNull(Auth::user());
});

test('панель: право users.manage без users.impersonate на кнопку не пускает', function (): void {
    ['target' => $target] = impersonateTestUsers();

    // users.manage есть — список и карточка доступны, а вот подмена уже нет:
    // это отдельное, более узкое право у самого маршрута
    $role = Role::create([
        'name'        => 'Ведёт пользователей ' . bin2hex(random_bytes(3)),
        'permissions' => ['users.manage'],
    ]);

    $manager = User::register('http_impersonate_manager_' . bin2hex(random_bytes(3)), 'секрет123', [
        'email'   => 'imp_manager' . bin2hex(random_bytes(3)) . '@example.com',
        'role_id' => $role->id(),
    ]);

    afterTests(static function () use ($manager, $role): void {
        $manager->forceDelete();
        $role->forceDelete();
    });

    assertStatus(403, httpRequest('POST', '/admin/users/' . $target->id() . '/impersonate', $manager));
});

test('панель: вход под пользователем и возврат через настоящие маршруты', function (): void {
    ['admin' => $admin, 'target' => $target] = impersonateTestUsers();

    try {
        $started = httpRequest('POST', '/admin/users/' . $target->id() . '/impersonate', $admin);

        assertStatus(302, $started);
        assertTrue(Auth::isImpersonating(), 'маршрут должен был записать подмену в сессию');
        assertSame($target->id(), (int) ($_SESSION['auth_impersonate'] ?? 0));

        // Остановка не требует users.manage — маршрут вне группы /users и без can:
        $stopped = httpRequest('POST', '/admin/impersonate/stop', $target);

        assertStatus(302, $stopped);
        assertContains('/admin/users/' . $target->id(), (string) $stopped->header('Location'));
    } finally {
        Auth::stopImpersonating();
    }

    assertFalse(Auth::isImpersonating(), 'после остановки сессия чистая для следующих тестов');
});

test('панель: входить под собой и под отключённым нельзя — маршрут отвечает редиректом с ошибкой', function (): void {
    ['admin' => $admin, 'target' => $target] = impersonateTestUsers();

    $self = httpRequest('POST', '/admin/users/' . $admin->id() . '/impersonate', $admin);

    assertStatus(302, $self);
    assertFalse(Auth::isImpersonating(), 'себя подменять не стали');

    $target->forceFill(['active' => 0])->save();

    try {
        $disabled = httpRequest('POST', '/admin/users/' . $target->id() . '/impersonate', $admin);

        assertStatus(302, $disabled);
        assertFalse(Auth::isImpersonating(), 'отключённого не подменяем');
    } finally {
        $target->forceFill(['active' => 1])->save();
    }
});

