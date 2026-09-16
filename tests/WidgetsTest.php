<?php

declare(strict_types=1);

/**
 * Карточки «Обзора»: видимость решает право, а не один список на всех.
 */

use Rsgrinko\Proton\Access\Permission;
use Rsgrinko\Proton\Access\Viewer;
use Rsgrinko\Proton\Cache\Cache;
use Rsgrinko\Proton\Database\Model\Factory;
use Rsgrinko\Proton\Models\Role;
use Rsgrinko\Proton\Models\User;
use Rsgrinko\Proton\Support\Widgets;

test('карточки: право решает, кому она видна', function (): void {
    withOwnDatabase(static function (): void {
        Widgets::reset();
        Cache::flush();

        // Своё право нужно зарегистрировать — Role::permissions() фильтрует
        // хранимый список по реестру, чужого кода тут нет, поэтому регистрируем сами
        if (!Permission::known('demo.secret')) {
            Permission::register('demo.secret', 'Секретная карточка', 'Тест');
        }

        Widgets::register('demo.closed', 'Только своим', 'demo.secret', static fn (): array => ['value' => 1]);
        Widgets::register('demo.open', 'Всем', '', static fn (): array => ['value' => 2]);

        /** @var Role $role */
        $role = Role::create(['name' => 'Без секрета', 'permissions' => []]);

        /** @var User $user */
        $user = Factory::of(User::class)->create(['role_id' => $role->id()]);

        $seen = Widgets::for(Viewer::fromUser($user));

        assertFalse(isset($seen['demo.closed']), 'нет права — нет карточки');
        assertTrue(isset($seen['demo.open']), 'открытая карточка видна всем');
        assertSame(2, $seen['demo.open']['value']);

        $role->forceFill(['permissions' => ['demo.secret']])->save();

        $seenAfter = Widgets::for(Viewer::fromUser(assertNotNull(User::find($user->id()))));

        assertTrue(isset($seenAfter['demo.closed']), 'право выдали — карточка появилась');

        Widgets::reset();
        Cache::flush();
    });
});

test('карточки: считаются не на каждый показ, а раз в окно', function (): void {
    withOwnDatabase(static function (): void {
        Widgets::reset();
        Cache::flush();

        $calls = 0;

        Widgets::register('demo.counted', 'Считает вызовы', '', static function () use (&$calls): array {
            $calls++;

            return ['value' => $calls];
        });

        Widgets::for(Viewer::full());
        Widgets::for(Viewer::full());

        assertSame(1, $calls, 'второй показ взял значение из кэша');

        Widgets::reset();
        Cache::flush();
    });
});

test('http: обзор показывает только те карточки, на которые есть право', function (): void {
    Widgets::reset();
    Cache::flush();

    // Только system.view — ни users.manage, ни notes.view, ни system.manage
    /** @var Role $limited */
    $limited = Role::create(['name' => 'Обзор ' . bin2hex(random_bytes(3)), 'permissions' => [Permission::SYSTEM_VIEW]]);

    /** @var User $user */
    $user = User::register('http_dashboard_' . bin2hex(random_bytes(3)), 'секрет123', [
        'email'   => 'dash' . bin2hex(random_bytes(3)) . '@example.com',
        'role_id' => $limited->id(),
    ]);

    $response = httpRequest('GET', '/admin', $user);

    assertStatus(200, $response);
    assertSee('Состояние', $response, 'health-карточка доступна по system.view');
    assertDontSee('Пользователей', $response, 'без users.manage карточки пользователей нет');
    assertDontSee('Заметок', $response, 'без notes.view карточки заметок нет');
    assertDontSee('В очереди', $response, 'без system.manage карточки очереди нет');
    assertDontSee('Последние действия', $response, 'без audit.view журнала на обзоре нет');

    $user->forceDelete();
    $limited->forceDelete();

    Widgets::reset();
    Cache::flush();
});
