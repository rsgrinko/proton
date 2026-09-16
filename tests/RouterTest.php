<?php

declare(strict_types=1);

/**
 * Роутер: сопоставление, параметры, группы, прослойки, имена.
 */

use Rsgrinko\Proton\Http\Request;
use Rsgrinko\Proton\Http\Response;
use Rsgrinko\Proton\Http\Router;
use Rsgrinko\Proton\Support\ProtonException;

test('роутер: простой маршрут и 404', function (): void {
    $router = new Router();

    $router->get('/hello', static fn (): Response => Response::text('привет'));

    assertSame('привет', $router->dispatch(Request::create('GET', '/hello'))->body());

    $e = assertThrows(static fn () => $router->dispatch(Request::create('GET', '/nope')));
    assertTrue($e instanceof ProtonException, 'ожидалась ProtonException');
    assertSame(404, $e->getCode());
});

test('роутер: метод не тот — 405, а не 404', function (): void {
    $router = new Router();

    $router->get('/only-get', static fn (): Response => Response::text('ок'));

    $e = assertThrows(static fn () => $router->dispatch(Request::create('POST', '/only-get')));
    assertTrue($e instanceof ProtonException, 'ожидалась ProtonException');
    assertSame(405, $e->getCode());
});

test('роутер: параметры адреса приводятся к типу аргумента', function (): void {
    $router = new Router();

    $router->get('/items/{id:\d+}', static fn (Request $request, array $params): Response => Response::text(
        var_export(is_string($params['id']), true)
    ));

    assertSame("true", $router->dispatch(Request::create('GET', '/items/7'))->body());

    // Ограничение работает: буквы под \d+ не подходят
    $e = assertThrows(static fn () => $router->dispatch(Request::create('GET', '/items/abc')));
    assertTrue($e instanceof ProtonException, 'ожидалась ProtonException');
    assertSame(404, $e->getCode());
});

test('роутер: группы складывают префиксы и прослойки', function (): void {
    $router = new Router();

    $router->middleware('mark', static function (Request $request, callable $next): Response {
        $response = $next($request);

        return Response::text('[' . $response->body() . ']');
    });

    $router->group(['prefix' => '/admin', 'middleware' => 'mark'], function (Router $router): void {
        $router->group(['prefix' => '/users'], function (Router $router): void {
            $router->get('/list', static fn (): Response => Response::text('список'));
        });
    });

    assertSame('[список]', $router->dispatch(Request::create('GET', '/admin/users/list'))->body());
});

test('роутер: прослойка получает аргумент через двоеточие', function (): void {
    $router = new Router();

    $router->middleware('say', static function (Request $request, callable $next, string $word = ''): Response {
        return Response::text($word);
    });

    $router->get('/said', static fn (): Response => Response::text('не должно дойти'))->middleware('say:привет');

    assertSame('привет', $router->dispatch(Request::create('GET', '/said'))->body());
});

test('роутер: незарегистрированная прослойка — сразу ошибка', function (): void {
    $router = new Router();

    $router->get('/broken', static fn (): Response => Response::text('ок'))->middleware('такой-нет');

    $error = assertThrows(static function () use ($router): void {
        $router->dispatch(Request::create('GET', '/broken'));
    });

    assertTrue($error instanceof ProtonException);
});

test('роутер: адрес собирается по имени, лишнее уходит в query', function (): void {
    $router = new Router();

    $router->get('/notes/{id:\d+}', static fn (): Response => Response::text('ок'))->name('test.notes.show');

    assertSame('/notes/5', Router::url('test.notes.show', ['id' => 5]));
    assertSame('/notes/5?page=2', Router::url('test.notes.show', ['id' => 5, 'page' => 2]));

    assertThrows(static function (): void {
        Router::url('test.notes.show');
    }, 'без обязательного параметра адрес собрать нельзя');

    assertThrows(static function (): void {
        Router::url('такого-маршрута-нет');
    });
});

test('роутер: хвостовой слэш не меняет маршрут', function (): void {
    $router = new Router();

    $router->get('/tail', static fn (): Response => Response::text('ок'));

    assertSame('ок', $router->dispatch(Request::create('GET', '/tail/'))->body());
});

test('запрос: понимает JSON, форму и подменённый метод', function (): void {
    $json = Request::create('POST', '/x', ['a' => 1], [], ['content-type' => 'application/json']);

    assertSame(1, $json->input('a'));
    assertTrue($json->wantsJson());
    assertTrue($json->isWriting());

    $plain = Request::create('GET', '/x');

    assertFalse($plain->isWriting());
    assertSame('умолчание', $plain->input('нет', 'умолчание'));
});

test('запрос: сломанный JSON отличается от пустого тела', function (): void {
    $request = Request::create('POST', '/x', [], [], ['content-type' => 'application/json']);

    $request->rawBody = '{"a": ,}';

    assertNotNull($request->bodyError(), 'о сломанном JSON нужно сказать прямо');

    $empty = Request::create('POST', '/x', [], [], ['content-type' => 'application/json']);

    assertNull($empty->bodyError(), 'честно пустое тело — не ошибка');
});
