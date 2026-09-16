<?php

declare(strict_types=1);

/**
 * Панель отладки: маршрут текущего запроса и что в неё попадает.
 *
 * Profiler::enabled() решает раз на весь процесс по APP_DEBUG из .env (см.
 * Profiler::enabled()) — поэтому здесь не «включаем» отладку через withConfig,
 * а просто требуем то, что уже решено при старте раннера. В застройке для
 * разработки APP_DEBUG=true, иначе тест честно скажет, что пропускать нечего.
 */

use Rsgrinko\Proton\Http\Router;
use Rsgrinko\Proton\Support\Profiler;

test('маршрут: Router::current() знает, кто обслужил запрос', function (): void {
    if (!Profiler::enabled()) {
        skipTest('APP_DEBUG выключен в этом окружении');
    }

    // Guest-прослойка у /login сама проверяет, что приложение установлено —
    // без единого пользователя в базе она увела бы на /install
    httpAdmin();

    $response = httpRequest('GET', '/login');

    assertStatus(200, $response);
    assertSame('login', Router::current()?->name);
});

test('панель отладки: маршрут, роль и число строк видны в самой странице', function (): void {
    if (!Profiler::enabled()) {
        skipTest('APP_DEBUG выключен в этом окружении');
    }

    Profiler::reset();

    $response = httpRequest('GET', '/admin/users', httpAdmin());

    assertStatus(200, $response);
    assertSee('admin.users', $response, 'имя маршрута попадает в панель');
    assertSee((string) httpAdmin()->id(), $response, 'id вошедшего виден в панели');
    assertSee('стр.', $response, 'число строк у самого долгого запроса показано');
});
