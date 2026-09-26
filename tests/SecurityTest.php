<?php

declare(strict_types=1);

/**
 * Блокировки адресов, журнал подозрительных событий и срок жизни ключей API.
 */

use Rsgrinko\Proton\Auth\Auth;
use Rsgrinko\Proton\Database\Model\Factory;
use Rsgrinko\Proton\Models\ApiToken;
use Rsgrinko\Proton\Models\BlockedIp;
use Rsgrinko\Proton\Models\SecurityEvent;
use Rsgrinko\Proton\Models\User;

test('блокировка: адрес закрывается на срок и открывается обратно', function (): void {
    withOwnDatabase(static function (): void {
        assertFalse(BlockedIp::blocked('203.0.113.10'));

        BlockedIp::block('203.0.113.10', 'перебор паролей', 60);

        assertTrue(BlockedIp::blocked('203.0.113.10'));
        assertFalse(BlockedIp::blocked('203.0.113.11'), 'соседний адрес не при чём');

        BlockedIp::unblock('203.0.113.10');

        assertFalse(BlockedIp::blocked('203.0.113.10'));
    });
});

test('блокировка: просроченная запись не держит и убирается уборкой', function (): void {
    withOwnDatabase(static function (): void {
        $block = BlockedIp::block('203.0.113.20', 'на минуту', 1);

        // Отматываем срок назад — так выглядит запись, у которой время вышло
        $block->forceFill(['until' => date('Y-m-d H:i:s', time() - 60)])->save();

        assertFalse(BlockedIp::blocked('203.0.113.20'), 'срок вышел — дверь открыта');

        assertSame(1, BlockedIp::purgeExpired());
        assertSame(0, BlockedIp::query()->count());

        // Блокировка без срока уборкой не снимается
        BlockedIp::block('203.0.113.21', 'навсегда', 0);

        assertSame(0, BlockedIp::purgeExpired());
        assertTrue(BlockedIp::blocked('203.0.113.21'));
    });
});

test('блокировка: закрытый адрес не пускают даже на форму входа', function (): void {
    withOwnDatabase(static function (): void {
        Factory::of(User::class)->create();

        assertStatus(200, get('/login'));

        // Тесты ходят с адреса, который отдаёт Request::create
        $ip = Rsgrinko\Proton\Http\Request::create('GET', '/login')->ip();

        BlockedIp::block($ip, 'проверка', 60);

        $response = get('/login');

        assertStatus(403, $response);
        assertSee('Доступ с этого адреса закрыт', $response);

        // И заход попал в журнал
        assertTrue(SecurityEvent::query()->where('kind', SecurityEvent::IP_BLOCKED)->count() > 0);

        BlockedIp::unblock($ip);

        assertStatus(200, get('/login'));
    });
});

test('журнал: неудачный вход записывается с логином и адресом', function (): void {
    withOwnDatabase(static function (): void {
        /** @var User $user */
        $user = Factory::of(User::class)->create();

        $before = SecurityEvent::query()->count();

        $response = post('/login', ['login' => (string) $user->login, 'password' => 'не тот пароль']);

        assertStatus(302, $response);
        assertSame($before + 1, SecurityEvent::query()->count());

        /** @var SecurityEvent $event */
        $event = assertNotNull(SecurityEvent::query()->orderBy('id', 'desc')->first());

        assertSame(SecurityEvent::LOGIN_FAILED, (string) $event->raw('kind'));
        assertSame((string) $user->login, (string) $event->raw('login'));
    });
});

test('журнал: считает события одного адреса за окно', function (): void {
    withOwnDatabase(static function (): void {
        foreach (['ivan', 'ivan', 'petr'] as $login) {
            SecurityEvent::record(SecurityEvent::LOGIN_FAILED, $login);
        }

        $ip = SecurityEvent::query()->orderBy('id', 'desc')->first()?->raw('ip');

        assertSame(3, SecurityEvent::countFor((string) $ip, SecurityEvent::LOGIN_FAILED, 60));
        assertSame(0, SecurityEvent::countFor('203.0.113.99', SecurityEvent::LOGIN_FAILED, 60));

        // Старое за окно не попадает
        SecurityEvent::query()->update(['created_at' => date('Y-m-d H:i:s', time() - 7200)]);

        assertSame(0, SecurityEvent::countFor((string) $ip, SecurityEvent::LOGIN_FAILED, 60));
    });
});

test('ключи: срок задаётся при выпуске и закрывает доступ по истечении', function (): void {
    withOwnDatabase(static function (): void {
        /** @var User $owner */
        $owner = Factory::of(User::class)->create();

        $issued = ApiToken::issue('на неделю', $owner->id(), '', 7);

        /** @var ApiToken $token */
        $token = $issued['token'];

        assertTrue($token->expiresAt() !== '', 'срок записан');
        assertSame(7, $token->daysLeft());
        assertFalse($token->expired());
        assertNotNull(ApiToken::match($issued['key']), 'пока не истёк — работает');

        // Отматываем срок назад
        $token->forceFill(['expires_at' => date('Y-m-d H:i:s', time() - 60)])->save();

        assertTrue(ApiToken::find($token->id())?->expired());
        assertNull(ApiToken::match($issued['key']), 'истёкший ключ не подходит');

        // Ключ без срока живёт всегда
        $forever = ApiToken::issue('без срока', $owner->id());

        assertSame(-1, $forever['token']->daysLeft());
        assertNotNull(ApiToken::match($forever['key']));
    });
});

test('ключи: в напоминание попадают только те, кому осталось немного', function (): void {
    withOwnDatabase(static function (): void {
        /** @var User $owner */
        $owner = Factory::of(User::class)->create();

        ApiToken::issue('скоро', $owner->id(), '', 3);
        ApiToken::issue('не скоро', $owner->id(), '', 90);
        ApiToken::issue('без срока', $owner->id());

        $expiring = ApiToken::expiringWithin(7);

        assertCount(1, $expiring);
        assertSame('скоро', (string) $expiring[0]->name);
    });
});

test('безопасность: X-Forwarded-Proto слушаем только от своего прокси', function (): void {
    $saved = $_SERVER;

    try {
        unset($_SERVER['HTTPS']);
        $_SERVER['REMOTE_ADDR']            = '203.0.113.7';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';

        withConfig(['app.url' => 'http://example.test', 'app.trusted_proxies' => ''], static function (): void {
            assertFalse(Auth::isHttps(), 'заголовок от чужого не в счёт');
        });

        withConfig(['app.url' => 'http://example.test', 'app.trusted_proxies' => '203.0.113.0/24'], static function (): void {
            assertTrue(Auth::isHttps(), 'от своего прокси — верим');
        });

        unset($_SERVER['HTTP_X_FORWARDED_PROTO']);

        withConfig(['app.url' => 'https://example.test', 'app.trusted_proxies' => ''], static function (): void {
            assertTrue(Auth::isHttps(), 'приложение на https — куки Secure всегда');
        });
    } finally {
        $_SERVER = $saved;
    }
});
