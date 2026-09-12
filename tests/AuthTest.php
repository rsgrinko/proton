<?php

declare(strict_types=1);

/**
 * Авторизация: пароли, попытки входа, «запомнить меня», устройства, ссылки.
 */

use Rsgrinko\Proton\Auth\Auth;
use Rsgrinko\Proton\Auth\Crypto;
use Rsgrinko\Proton\Auth\Devices;
use Rsgrinko\Proton\Auth\Password;
use Rsgrinko\Proton\Models\AuthToken;
use Rsgrinko\Proton\Models\RememberToken;
use Rsgrinko\Proton\Models\Role;
use Rsgrinko\Proton\Models\User;
use Rsgrinko\Proton\RateLimit\RateLimiter;

test('пароль: короткий не принимается, хеш проверяется', function (): void {
    assertNotNull(Password::check('123'), 'слишком короткий пароль');
    assertNull(Password::check('достаточнодлинный'));

    $hash = Password::hash('секрет123');

    assertTrue(Password::verify('секрет123', $hash));
    assertFalse(Password::verify('другой', $hash));
    assertFalse(Password::verify('секрет123', ''), 'пустой хеш никому не подходит');
});

test('вход: неверный пароль не пускает, верный — пускает', function (): void {
    withOwnDatabase(static function (): void {
        User::register('auth_test', 'секрет123', ['role_id' => Role::admin()?->id() ?? 0]);

        assertNull(Auth::attempt('auth_test', 'не тот', '127.0.0.1')['user']);
        assertNotNull(Auth::attempt('auth_test', 'секрет123', '127.0.0.1')['user']);
        assertNotNull(Auth::attempt('auth_test', 'секрет123', '127.0.0.1')['user'], 'вход по логину');
    });
});

test('вход: отключённого пользователя не пускаем', function (): void {
    withOwnDatabase(static function (): void {
        $user = User::register('auth_off', 'секрет123');

        $user->forceFill(['active' => 0])->save();

        assertNull(Auth::attempt('auth_off', 'секрет123', '127.0.0.1')['user']);
    });
});

test('вход: подбор пароля упирается в ограничение', function (): void {
    withOwnDatabase(static function (): void {
        User::register('auth_brute', 'секрет123');

        withConfig(['auth.max_attempts' => 3, 'auth.attempts_window' => 900], static function (): void {
            for ($i = 0; $i < 3; $i++) {
                Auth::attempt('auth_brute', 'мимо', '10.0.0.1');
            }

            $result = Auth::attempt('auth_brute', 'секрет123', '10.0.0.1');

            assertNull($result['user'], 'даже верный пароль после перебора не пускает');
            assertContains('Слишком много', $result['error']);

            // С другого адреса ограничение не действует
            assertNotNull(Auth::attempt('auth_brute', 'секрет123', '10.0.0.2')['user']);
        });
    });
});

test('вход: удачная попытка обнуляет счётчик', function (): void {
    withOwnDatabase(static function (): void {
        User::register('auth_reset', 'секрет123');

        $limiter = new RateLimiter();

        Auth::attempt('auth_reset', 'мимо', '10.0.0.3');

        assertSame(1, $limiter->count('login:10.0.0.3'));

        Auth::attempt('auth_reset', 'секрет123', '10.0.0.3');

        assertSame(0, $limiter->count('login:10.0.0.3'));
    });
});

test('запомнить меня: токен меняется при каждом входе', function (): void {
    withOwnDatabase(static function (): void {
        $user = User::register('remember_test', 'секрет123');

        $cookie = RememberToken::issue($user->id(), 30, '127.0.0.1', 'тест');

        $found = RememberToken::match($cookie);

        assertNotNull($found['token']);
        assertFalse($found['grace']);

        $rotated = assertNotNull($found['token'])->rotate('127.0.0.1', 'тест');

        assertTrue($rotated !== $cookie, 'секрет должен смениться');
        assertSame(RememberToken::selectorOf($cookie), RememberToken::selectorOf($rotated), 'селектор остаётся');
    });
});

test('запомнить меня: старая кука в окно снисхождения ещё работает', function (): void {
    withOwnDatabase(static function (): void {
        $user = User::register('remember_grace', 'секрет123');

        $cookie = RememberToken::issue($user->id(), 30, '127.0.0.1', 'тест');
        $token  = assertNotNull(RememberToken::match($cookie)['token']);

        $token->rotate('127.0.0.1', 'тест');

        // Соседний запрос той же страницы приходит с прежней кукой — и это
        // не кража, а обычное поведение браузера
        $again = RememberToken::match($cookie);

        assertNotNull($again['token']);
        assertTrue($again['grace']);
    });
});

test('запомнить меня: просроченная кука гасит токен', function (): void {
    withOwnDatabase(static function (): void {
        $user = User::register('remember_old', 'секрет123');

        $cookie = RememberToken::issue($user->id(), 30, '127.0.0.1', 'тест');
        $token  = assertNotNull(RememberToken::match($cookie)['token']);

        $token->forceFill(['expires_at' => date('Y-m-d H:i:s', time() - 60)])->save();

        assertNull(RememberToken::match($cookie)['token']);
        assertSame(0, RememberToken::query()->where('user_id', $user->id())->count(), 'протухший токен убирается');
    });
});

test('запомнить меня: чужой секрет с тем же селектором гасит токен целиком', function (): void {
    withOwnDatabase(static function (): void {
        $user = User::register('remember_theft', 'секрет123');

        $cookie   = RememberToken::issue($user->id(), 30, '127.0.0.1', 'тест');
        $selector = RememberToken::selectorOf($cookie);

        // Кто-то подобрал селектор, но не знает секрета
        assertNull(RememberToken::match($selector . ':подделка')['token']);
        assertSame(0, RememberToken::query()->where('selector', $selector)->count(), 'токен гасится целиком');
    });
});

test('устройства: список собирается из сеансов и долгих кук', function (): void {
    withOwnDatabase(static function (): void {
        $user = User::register('devices_test', 'секрет123');

        Rsgrinko\Proton\Models\UserSession::open($user->id(), 'sid-1', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0) Chrome/120');
        RememberToken::issue($user->id(), 30, '10.0.0.5', 'Mozilla/5.0 (iPhone) Safari');

        $devices = Devices::of($user->id());

        assertCount(2, $devices, 'сеанс без галки и запомненный браузер — два разных устройства');

        assertTrue(Devices::revoke($user->id(), $devices[0]['id']));
        assertCount(1, Devices::of($user->id()));
    });
});

test('устройства: выход на остальных гасит и сеансы, и куки', function (): void {
    withOwnDatabase(static function (): void {
        $user = User::register('logout_all', 'секрет123');

        Rsgrinko\Proton\Models\UserSession::open($user->id(), 'sid-2', '127.0.0.1', 'браузер');
        RememberToken::issue($user->id(), 30, '127.0.0.1', 'браузер');

        $user->logoutEverywhere();

        assertSame(0, RememberToken::query()->where('user_id', $user->id())->count());
        assertCount(0, Devices::of($user->id()));
        assertTrue(trim((string) $user->raw('sessions_from')) !== '', 'отметка о завершении сеансов стоит');
    });
});

test('смена пароля завершает прежние сеансы', function (): void {
    withOwnDatabase(static function (): void {
        $user = User::register('password_change', 'секрет123');

        RememberToken::issue($user->id(), 30, '127.0.0.1', 'браузер');

        $user->setPassword('новыйсекрет');
        $user->save();

        assertTrue(trim((string) $user->raw('sessions_from')) !== '');
        assertTrue($user->verifyPassword('новыйсекрет'));
    });
});

test('ссылки: одноразовый токен подтверждения и сброса', function (): void {
    withOwnDatabase(static function (): void {
        $user = User::register('token_test', 'секрет123');

        $token = AuthToken::issue($user->id(), AuthToken::RESET, '127.0.0.1');

        assertNull(AuthToken::match($token, AuthToken::VERIFY), 'тип должен совпадать');

        $found = assertNotNull(AuthToken::match($token, AuthToken::RESET));

        $found->markUsed();

        assertNull(AuthToken::match($token, AuthToken::RESET), 'второй раз ссылка не работает');
    });
});

test('ссылки: новая гасит предыдущую того же типа', function (): void {
    withOwnDatabase(static function (): void {
        $user = User::register('token_replace', 'секрет123');

        $first = AuthToken::issue($user->id(), AuthToken::RESET);

        AuthToken::issue($user->id(), AuthToken::RESET);

        assertNull(AuthToken::match($first, AuthToken::RESET), 'действующей должна быть одна ссылка');
    });
});

test('шифрование: значение возвращается, подпись сходится', function (): void {
    assertTrue(Crypto::hasKey(), 'в тестах ключ задан');

    $encrypted = Crypto::encrypt('пароль от smtp');

    assertNotContains('пароль', $encrypted, 'в базе не должно быть открытого значения');
    assertSame('пароль от smtp', Crypto::decrypt($encrypted));

    $signature = Crypto::sign('данные');

    assertTrue(Crypto::verify('данные', $signature));
    assertFalse(Crypto::verify('другие данные', $signature));
});
