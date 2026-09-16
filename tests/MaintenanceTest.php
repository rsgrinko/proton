<?php

declare(strict_types=1);

/**
 * Режим обслуживания: флаг на диске и 503 всем, кроме допущенных.
 */

use Rsgrinko\Proton\Database\Model\Factory;
use Rsgrinko\Proton\Models\Role;
use Rsgrinko\Proton\Models\User;
use Rsgrinko\Proton\Support\Maintenance;

test('режим обслуживания: включение, состояние, выключение', function (): void {
    Maintenance::deactivate();

    assertFalse(Maintenance::active());
    assertSame(['message' => '', 'allow' => '', 'retry' => 0, 'since' => 0], Maintenance::payload());

    Maintenance::activate('Обновляем базу', 'unknown', 60);

    assertTrue(Maintenance::active());

    $payload = Maintenance::payload();

    assertSame('Обновляем базу', $payload['message']);
    assertSame('unknown', $payload['allow']);
    assertSame(60, $payload['retry']);
    assertTrue($payload['since'] > 0, 'отметка времени проставлена');

    assertTrue(Maintenance::allows('unknown'), 'адрес из списка допуска пропущен');
    assertFalse(Maintenance::allows('203.0.113.9'), 'постороннего адреса в списке нет');

    Maintenance::deactivate();

    assertFalse(Maintenance::active());
});

test('режим обслуживания: гостю 503 с сообщением, своим (system.manage) — как обычно', function (): void {
    withOwnDatabase(static function (): void {
        /** @var User $admin */
        $admin = Factory::of(User::class)->create(['role_id' => Role::admin()?->id() ?? 0]);

        afterTests(static function () use ($admin): void {
            Maintenance::deactivate();
            $admin->forceDelete();
        });

        Maintenance::activate('Идут технические работы');

        $guestResponse = get('/');

        assertStatus(503, $guestResponse);
        assertSee('Идут технические работы', $guestResponse);

        actingAs($admin);
        assertStatus(200, get('/'), 'system.manage работает и под режимом обслуживания');
        actingAs(null);

        Maintenance::deactivate();

        assertStatus(302, get('/'), 'без входа «/» обычно уводит на форму входа, а не 503');
    });
});

test('режим обслуживания: список допуска пускает мимо страницы «идут работы»', function (): void {
    Maintenance::activate('', 'unknown');   // в тестах Request::ip() всегда 'unknown'

    // Не 503 — правило допуска сработало, дальше это уже обычное поведение маршрута
    assertStatus(302, get('/'), 'гость из списка допуска долетает до обычной логики маршрута');

    Maintenance::deactivate();
});

test('режим обслуживания: API получает JSON и код 503, а не страницу', function (): void {
    Maintenance::activate('Идут работы');

    $response = get('/', [], ['accept' => 'application/json']);

    assertStatus(503, $response);
    assertContains('"message"', $response->body());
    assertContains('Идут работы', $response->body());

    Maintenance::deactivate();
});
