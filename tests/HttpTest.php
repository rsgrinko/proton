<?php

declare(strict_types=1);

/**
 * Настоящие запросы к ядру: обход всех GET-страниц, доступ, токен форм и API.
 *
 * Так ловится то, что раньше проверялось только руками: забытое имя маршрута,
 * отвалившаяся прослойка, ошибка во вьюхе.
 */

use App\Models\Note;
use Rsgrinko\Proton\Access\Permission;
use Rsgrinko\Proton\Auth\Auth;
use Rsgrinko\Proton\Auth\Csrf;
use Rsgrinko\Proton\Comments\Comment;
use Rsgrinko\Proton\Http\Kernel;
use Rsgrinko\Proton\Http\Request;
use Rsgrinko\Proton\Http\Response;
use Rsgrinko\Proton\Models\ApiToken;
use Rsgrinko\Proton\Models\Role;
use Rsgrinko\Proton\Models\User;
use Rsgrinko\Proton\Models\UserNotification;
use Rsgrinko\Proton\Models\IncomingHook;
use Rsgrinko\Proton\Models\Webhook;
use Rsgrinko\Proton\Models\WebhookDelivery;
use Rsgrinko\Proton\Support\Settings;
use Rsgrinko\Proton\Webhooks\Incoming;

/**
 * Запрос к ядру от лица пользователя (или гостя, если его нет).
 *
 * @param array<string, mixed> $body
 * @param array<string, mixed> $query
 */
function httpRequest(string $method, string $path, ?User $user = null, array $body = [], array $query = [], array $headers = []): Response
{
    Auth::forget();
    Auth::actAs($user);

    if (in_array(strtoupper($method), ['POST', 'PUT', 'PATCH', 'DELETE'], true) && !isset($body[Csrf::FIELD])) {
        $body[Csrf::FIELD] = Csrf::token();
    }

    $response = (new Kernel())->handle(Request::create($method, $path, $body, $query, $headers));

    Auth::actAs(null);
    Auth::forget();

    return $response;
}

/**
 * Администратор для проверок.
 */
function httpAdmin(): User
{
    static $user = null;

    if ($user instanceof User) {
        return $user;
    }

    $user = User::register('http_admin_' . bin2hex(random_bytes(3)), 'секрет123', [
        'email'   => 'admin' . bin2hex(random_bytes(3)) . '@example.com',
        'role_id' => Role::admin()?->id() ?? 0,
    ]);

    afterTests(static function () use ($user): void {
        Note::query()->withTrashed()->where('user_id', $user->id())->forceDelete();
        ApiToken::query()->where('user_id', $user->id())->forceDelete();
        $user->forceDelete();
    });

    return $user;
}

/**
 * Обычный пользователь: свои записи, без управления сервисом.
 */
function httpUser(): User
{
    static $user = null;

    if ($user instanceof User) {
        return $user;
    }

    $role = Role::query()->where('is_system', 0)->first();

    $user = User::register('http_user_' . bin2hex(random_bytes(3)), 'секрет123', [
        'email'   => 'user' . bin2hex(random_bytes(3)) . '@example.com',
        'role_id' => $role?->id() ?? 0,
    ]);

    afterTests(static function () use ($user): void {
        Note::query()->withTrashed()->where('user_id', $user->id())->forceDelete();
        $user->forceDelete();
    });

    return $user;
}

/**
 * Подписка на события для обхода страниц панели.
 */
function httpWebhook(): Webhook
{
    static $webhook = null;

    if ($webhook instanceof Webhook) {
        return $webhook;
    }

    $webhook = Webhook::add('Подписка для обхода', 'https://example.com/hook', [Webhook::ALL_EVENTS]);

    afterTests(static function () use ($webhook): void {
        WebhookDelivery::query()->where('webhook_id', $webhook->id())->delete();
        $webhook->delete();
    });

    return $webhook;
}

test('http: гостя уводит на вход, а не показывает страницу', function (): void {
    // Пользователь в базе нужен: пока их нет, всё уводит на первый запуск
    httpAdmin();

    $response = httpRequest('GET', '/');

    assertStatus(302, $response);
    assertContains('/login', $response->header('Location'));
});

test('http: страницы для гостя открыты', function (): void {
    httpAdmin();

    foreach (['/login', '/register', '/password/forgot'] as $path) {
        assertStatus(200, httpRequest('GET', $path), 'страница ' . $path);
    }
});

test('http: пока приложение не установлено, всё уводит в установщик', function (): void {
    withOwnDatabase(static function (): void {
        $response = (new Kernel())->handle(Request::create('GET', '/login'));

        assertStatus(302, $response);
        assertContains('/install', $response->header('Location'));

        // Сам установщик при этом открыт — иначе им никто не воспользуется
        assertStatus(200, (new Kernel())->handle(Request::create('GET', '/install')));
    });
});

test('http: установленное приложение установщик обратно не пускает', function (): void {
    httpAdmin();

    $response = (new Kernel())->handle(Request::create('GET', '/install'));

    assertStatus(302, $response, 'мастером не должен воспользоваться прохожий');
    assertContains('/', $response->header('Location'));
});

test('http: вошедшему форма входа не нужна', function (): void {
    assertStatus(302, httpRequest('GET', '/login', httpAdmin()));
});

test('http: все страницы администратора отвечают', function (): void {
    $note = Note::create(['title' => 'Для обхода страниц']);

    $note->forceFill(['user_id' => httpAdmin()->id()])->save();

    $paths = [
        '/', '/profile', '/notifications',
        '/notes', '/notes/new', '/notes/' . $note->id(), '/notes/' . $note->id() . '/edit',
        '/admin', '/admin/users', '/admin/users/new', '/admin/users/' . httpAdmin()->id(),
        '/admin/roles', '/admin/roles/new', '/admin/roles/' . (Role::admin()?->id() ?? 1),
        '/admin/tokens', '/admin/audit', '/admin/logs', '/admin/system',
        '/admin/webhooks', '/admin/webhooks/new', '/admin/webhooks/' . httpWebhook()->id(),
        '/admin/settings', '/admin/backups', '/admin/reports',
    ];

    foreach ($paths as $path) {
        assertStatus(200, httpRequest('GET', $path, httpAdmin()), 'страница ' . $path);
    }
});

test('http: обычный пользователь не попадает в служебные разделы', function (): void {
    $closed = [
        '/admin', '/admin/users', '/admin/roles', '/admin/tokens',
        '/admin/audit', '/admin/logs', '/admin/system', '/admin/webhooks', '/admin/settings',
        '/admin/backups', '/admin/reports',
    ];

    foreach ($closed as $path) {
        assertStatus(403, httpRequest('GET', $path, httpUser()), 'закрытая страница ' . $path);
    }

    // А свои разделы — открыты
    assertStatus(200, httpRequest('GET', '/notes', httpUser()));
    assertStatus(200, httpRequest('GET', '/profile', httpUser()));
});

test('http: чужая запись для обычного пользователя не существует', function (): void {
    $note = Note::create(['title' => 'Чужая заметка']);

    $note->forceFill(['user_id' => httpAdmin()->id()])->save();

    $response = httpRequest('GET', '/notes/' . $note->id(), httpUser());

    // Именно «не найдено», а не «нет доступа»: постороннему знать нечего
    assertStatus(302, $response, 'уводит обратно в список');
    assertContains('/notes', $response->header('Location'));

    // И в списке чужой заметки тоже нет
    $list = httpRequest('GET', '/notes', httpUser());

    assertNotContains('Чужая заметка', $list->body());
});

test('http: комментарий к заметке пишет и стирает свой, чужой — только с notes.manage', function (): void {
    $note = Note::create(['title' => 'Заметка для обсуждения']);

    $note->forceFill(['user_id' => httpAdmin()->id()])->save();

    $response = httpRequest('POST', '/notes/' . $note->id() . '/comments', httpAdmin(), ['body' => 'Первый комментарий']);

    assertStatus(302, $response);

    $shown = httpRequest('GET', '/notes/' . $note->id(), httpAdmin());

    assertSee('Первый комментарий', $shown);

    $comment = assertNotNull(Comment::query()->where('entity', 'note')->where('entity_id', (string) $note->id())->first());

    // Читатель без notes.manage чужой комментарий не уберёт
    $role = Role::create(['name' => 'Только смотрит ' . bin2hex(random_bytes(3)), 'permissions' => ['notes.view']]);

    $viewer = User::register('http_comment_viewer_' . bin2hex(random_bytes(3)), 'секрет123', [
        'email'   => 'commentviewer' . bin2hex(random_bytes(3)) . '@example.com',
        'role_id' => $role->id(),
    ]);

    afterTests(static function () use ($viewer, $role): void {
        $viewer->forceDelete();
        $role->forceDelete();
    });

    $denied = httpRequest('POST', '/notes/' . $note->id() . '/comments/delete', $viewer, ['comment' => $comment->id()]);

    assertStatus(302, $denied);
    assertNotNull(Comment::find($comment->id()), 'чужой комментарий остался цел');

    // Сам автор свой комментарий убирает
    $removed = httpRequest('POST', '/notes/' . $note->id() . '/comments/delete', httpAdmin(), ['comment' => $comment->id()]);

    assertStatus(302, $removed);
    assertNull(Comment::find($comment->id()));
});

test('http: форма без токена ничего не меняет', function (): void {
    $before = Note::query()->where('user_id', httpAdmin()->id())->count();

    $response = httpRequest('POST', '/notes/new', httpAdmin(), [
        Csrf::FIELD => 'подделка',
        'title'     => 'Без токена',
    ]);

    assertStatus(403, $response);
    assertSame($before, Note::query()->where('user_id', httpAdmin()->id())->count(), 'запись не появилась');
});

test('http: форма с токеном создаёт запись и пишет в журнал', function (): void {
    $response = httpRequest('POST', '/notes/new', httpAdmin(), ['title' => 'Через форму', 'body' => 'тело']);

    assertStatus(302, $response);

    $note = assertNotNull(Note::query()->where('title', 'Через форму')->first());

    assertSame(httpAdmin()->id(), (int) $note->raw('user_id'));

    $entry = Rsgrinko\Proton\Models\AuditEntry::query()->where('entity', 'note')->orderBy('id', 'desc')->first();

    assertNotNull($entry, 'действие должно попасть в журнал');
});

test('http: неверные данные возвращают человека на форму', function (): void {
    $response = httpRequest('POST', '/notes/new', httpAdmin(), ['title' => '']);

    assertStatus(302, $response, 'без обязательного поля запись не создаётся');
    assertSame(0, Note::query()->where('title', '')->count());
});

test('http: лента уведомлений своя у каждого', function (): void {
    $mine   = UserNotification::add(httpAdmin()->id(), 'test.http', 'Моё уведомление', 'текст', '/profile');
    $others = UserNotification::add(httpUser()->id(), 'test.http', 'Чужое уведомление');

    afterTests(static function () use ($mine, $others): void {
        UserNotification::query()->whereIn('id', [$mine->id(), $others->id()])->delete();
    });

    $list = httpRequest('GET', '/notifications', httpAdmin());

    assertStatus(200, $list);
    assertContains('Моё уведомление', $list->body());
    assertNotContains('Чужое уведомление', $list->body(), 'чужих уведомлений в ленте нет');

    // Чтение ведёт по ссылке уведомления
    $read = httpRequest('POST', '/notifications/' . $mine->id() . '/read', httpAdmin());

    assertStatus(302, $read);
    assertContains('/profile', $read->header('Location'));
    assertTrue(assertNotNull(UserNotification::find($mine->id()))->read());

    // Чужое уведомление для администратора просто не существует
    $foreign = httpRequest('POST', '/notifications/' . $others->id() . '/read', httpAdmin());

    assertStatus(302, $foreign, 'уводит в ленту, а не показывает «нет доступа»');
    assertFalse(assertNotNull(UserNotification::find($others->id()))->read(), 'чужое не прочиталось');

    assertStatus(302, httpRequest('POST', '/notifications/read', httpAdmin()));
    assertSame(0, UserNotification::unreadFor(httpAdmin()->id()));
});

test('http: настройка правится из панели и сразу действует', function (): void {
    withConfig(['ui.per_page' => 25], static function (): void {
        $response = httpRequest('POST', '/admin/settings', httpAdmin(), [
            'settings' => ['ui.per_page' => '9'],
        ]);

        assertStatus(302, $response);
        assertSame(9, Rsgrinko\Proton\Support\Config::get('ui.per_page'), 'значение применилось к процессу');
        assertTrue(Settings::overridden('ui.per_page'));

        // Негодное значение до базы не доходит
        httpRequest('POST', '/admin/settings', httpAdmin(), ['settings' => ['ui.per_page' => 'много']]);

        assertSame(9, (int) Settings::value('ui.per_page'), 'осталось прежнее');

        // Сброс возвращает настройку к значению из .env
        assertStatus(302, httpRequest('POST', '/admin/settings/reset', httpAdmin(), ['key' => 'ui.per_page']));
        assertFalse(Settings::overridden('ui.per_page'));

        // И всё это попало в журнал действий
        $entry = Rsgrinko\Proton\Models\AuditEntry::query()
            ->where('entity', 'settings')
            ->orderBy('id', 'desc')
            ->first();

        assertNotNull($entry, 'правка настроек пишется в журнал');
    });
});

test('http: обычный пользователь настройки не правит', function (): void {
    $response = httpRequest('POST', '/admin/settings', httpUser(), ['settings' => ['ui.per_page' => '3']]);

    assertStatus(403, $response);
    assertFalse(Settings::overridden('ui.per_page'));
});

test('http: подписка на события заводится и проверяется из панели', function (): void {
    $response = httpRequest('POST', '/admin/webhooks/new', httpAdmin(), [
        'name'     => 'Через форму',
        'url'      => 'https://example.com/hooks/proton',
        'events'   => ['user.login'],
        'secret'   => 'секрет-из-формы',
    ]);

    assertStatus(302, $response);

    /** @var Webhook $webhook */
    $webhook = assertNotNull(Webhook::query()->where('name', 'Через форму')->first());

    assertSame('секрет-из-формы', $webhook->secret());
    assertSame(['user.login'], $webhook->events());

    // Пробная посылка только встаёт в очередь — в сеть тесты не ходят
    assertStatus(302, httpRequest('POST', '/admin/webhooks/' . $webhook->id() . '/test', httpAdmin()));

    $delivery = assertNotNull(
        WebhookDelivery::query()->where('webhook_id', $webhook->id())->orderBy('id', 'desc')->first()
    );

    assertSame(WebhookDelivery::PENDING, (string) $delivery->raw('status'));

    // Событие без подписчиков посылок не создаёт, с подписчиком — создаёт
    Rsgrinko\Proton\Webhooks\Webhooks::dispatch('user.logout', ['login' => 'кто-то']);

    assertSame(1, WebhookDelivery::query()->where('webhook_id', $webhook->id())->count(), 'чужое событие не уехало');

    Rsgrinko\Proton\Webhooks\Webhooks::dispatch('user.login', ['login' => 'кто-то']);

    assertSame(2, WebhookDelivery::query()->where('webhook_id', $webhook->id())->count());

    // Отключение и удаление — тоже из панели
    assertStatus(302, httpRequest('POST', '/admin/webhooks/' . $webhook->id() . '/toggle', httpAdmin()));
    assertFalse((bool) assertNotNull(Webhook::find($webhook->id()))->active);

    assertStatus(302, httpRequest('POST', '/admin/webhooks/' . $webhook->id() . '/delete', httpAdmin()));
    assertNull(Webhook::find($webhook->id()));
    assertSame(0, WebhookDelivery::query()->where('webhook_id', $webhook->id())->count(), 'журнал ушёл вместе с подпиской');
});

test('http: кнопка «Проверить» шлёт источнику пробную посылку и пишет в журнал', function (): void {
    Incoming::reset();

    Incoming::register('http_test', 'Проверка из панели', 'HTTP_TEST_HOOK_TOKEN', static function (): void {});

    putenv('HTTP_TEST_HOOK_TOKEN=токен-панели');

    $before = IncomingHook::query()->count();

    $response = httpRequest('POST', '/admin/webhooks/incoming/test', httpAdmin(), ['source' => 'http_test']);

    assertStatus(302, $response);
    assertSame($before + 1, IncomingHook::query()->count(), 'посылка легла в журнал сразу, не через очередь');

    $hook = assertNotNull(IncomingHook::query()->orderBy('id', 'desc')->first());

    assertSame(IncomingHook::DONE, (string) $hook->raw('status'));
    assertSame('incoming.test', (string) $hook->raw('event'));

    // Неизвестный источник не роняет страницу
    assertStatus(302, httpRequest('POST', '/admin/webhooks/incoming/test', httpAdmin(), ['source' => 'нет-такого']));

    putenv('HTTP_TEST_HOOK_TOKEN');
    Incoming::reset();
});

test('http: список фильтруется и сортируется параметрами адреса', function (): void {
    $admin = httpAdmin();
    $user  = httpUser();

    // Поиск по логину: чужой строки в ответе быть не должно
    $response = httpRequest('GET', '/admin/users', $admin, [], ['q' => (string) $admin->login]);

    assertStatus(200, $response);
    assertContains((string) $admin->login, $response->body());
    assertNotContains((string) $user->login, $response->body());

    // Отбор по роли и по признаку «активен» работает вместе с поиском
    $response = httpRequest('GET', '/admin/users', $admin, [], [
        'q'       => (string) $admin->login,
        'role_id' => (string) $user->raw('role_id'),
    ]);

    // Логин админа остался в поле поиска, поэтому смотрим на ссылку карточки
    assertNotContains('/admin/users/' . $admin->id() . '"', $response->body());

    // Сортировка по чужой колонке ничего не роняет — её просто нет в белом списке
    $response = httpRequest('GET', '/admin/users', $admin, [], ['sort' => 'password_hash', 'dir' => 'asc']);

    assertStatus(200, $response);
    // Имя колонки может мелькнуть в панели отладки, поэтому смотрим на само
    // условие сортировки и на ссылки страниц
    assertNotContains('ORDER BY password_hash', $response->body());
    assertNotContains('sort=password_hash', $response->body());

    $response = httpRequest('GET', '/admin/users', $admin, [], ['sort' => 'login', 'dir' => 'asc']);

    assertStatus(200, $response);
    assertContains('sort=login', $response->body(), 'ссылки страниц тащат сортировку за собой');
});

test('http: список выгружается файлом с учётом фильтров', function (): void {
    $admin = httpAdmin();

    $response = httpRequest('GET', '/admin/users/export', $admin, [], ['q' => (string) $admin->login]);

    assertStatus(200, $response);
    assertContains('attachment', $response->header('Content-Disposition'));
    assertContains('users-', $response->header('Content-Disposition'));
    assertContains((string) $admin->login, $response->body());
    assertNotContains((string) httpUser()->login, $response->body(), 'фильтр действует и в файле');

    // Выгрузка уносит данные наружу, поэтому попадает в журнал
    $entry = Rsgrinko\Proton\Models\AuditEntry::query()
        ->where('entity', 'user')
        ->orderBy('id', 'desc')
        ->first();

    assertContains('выгружен список', (string) assertNotNull($entry)->raw('description'));

    // Предел строк — не совет: за ним выгрузка отказывается и возвращает в список
    withConfig(['export.max_rows' => 1], static function () use ($admin): void {
        assertStatus(302, httpRequest('GET', '/admin/users/export', $admin));
    });
});

test('http: пользователь удаляется из панели, а себя удалить нельзя', function (): void {
    $victim = User::register('http_victim_' . bin2hex(random_bytes(3)), 'секрет123', [
        'email'   => 'victim' . bin2hex(random_bytes(3)) . '@example.com',
        'role_id' => httpUser()->raw('role_id'),
    ]);

    afterTests(static function () use ($victim): void {
        $victim->forceDelete();
    });

    assertStatus(302, httpRequest('POST', '/admin/users/' . $victim->id() . '/delete', httpAdmin()));
    assertNull(User::find($victim->id()));

    // Себя не удаляем: обработчик получает текущего пользователя из атрибутов запроса
    assertStatus(302, httpRequest('POST', '/admin/users/' . httpAdmin()->id() . '/delete', httpAdmin()));
    assertNotNull(User::find(httpAdmin()->id()));
});

test('http: неизвестный адрес — 404', function (): void {
    assertStatus(404, httpRequest('GET', '/нет-такой-страницы', httpAdmin()));
});

test('api: без ключа не пускает, с ключом отвечает', function (): void {
    $issued = ApiToken::issue('тест', httpAdmin()->id());

    assertStatus(401, httpRequest('GET', '/api/v1/notes'));

    $response = httpRequest('GET', '/api/v1/me', null, [], [], ['authorization' => 'Bearer ' . $issued['key']]);

    assertStatus(200, $response);
    assertContains((string) httpAdmin()->login, $response->body());
});

test('api: отключённый ключ не работает', function (): void {
    $issued = ApiToken::issue('отключённый', httpAdmin()->id());

    $issued['token']->forceFill(['active' => 0])->save();

    assertStatus(401, httpRequest('GET', '/api/v1/notes', null, [], [], ['authorization' => 'Bearer ' . $issued['key']]));
});

test('api: ключ, привязанный к адресу, чужой адрес не пускает', function (): void {
    $issued = ApiToken::issue('по адресу', httpAdmin()->id(), '10.0.0.0/8');

    // Запрос идёт с неизвестного адреса — в тестах это «unknown»
    assertStatus(403, httpRequest('GET', '/api/v1/notes', null, [], [], ['authorization' => 'Bearer ' . $issued['key']]));
});

test('api: заметки создаются, читаются и удаляются', function (): void {
    $issued  = ApiToken::issue('crud', httpAdmin()->id());
    $headers = ['authorization' => 'Bearer ' . $issued['key'], 'content-type' => 'application/json'];

    $created = httpRequest('POST', '/api/v1/notes', null, ['title' => 'Через API'], [], $headers);

    assertStatus(201, $created);

    $payload = json_decode($created->body(), true);
    $id      = (int) ($payload['data']['id'] ?? 0);

    assertTrue($id > 0, 'в ответе должен быть номер записи');

    assertStatus(200, httpRequest('GET', '/api/v1/notes/' . $id, null, [], [], $headers));
    assertStatus(200, httpRequest('PATCH', '/api/v1/notes/' . $id, null, ['title' => 'Изменено'], [], $headers));
    assertStatus(200, httpRequest('DELETE', '/api/v1/notes/' . $id, null, [], [], $headers));
    assertStatus(404, httpRequest('GET', '/api/v1/notes/' . $id, null, [], [], $headers));
});

test('api: показатели отдаются по ключу и только с правом', function (): void {
    $issued = ApiToken::issue('для мониторинга', httpAdmin()->id());

    $response = httpRequest('GET', '/api/v1/metrics', null, [], [], ['authorization' => 'Bearer ' . $issued['key']]);

    assertStatus(200, $response);
    assertContains('"requests"', $response->body());
    assertContains('"queue"', $response->body());

    // Ключ обычного пользователя показатели не получает
    $limited = ApiToken::issue('без права', httpUser()->id());

    assertStatus(403, httpRequest('GET', '/api/v1/metrics', null, [], [], [
        'authorization' => 'Bearer ' . $limited['key'],
    ]));
});

test('api: здоровье отвечает без ключа и разбито по зависимостям', function (): void {
    $response = httpRequest('GET', '/api/v1/health');

    assertStatus(200, $response);
    assertContains('"status": "ok"', $response->body());

    $payload = json_decode($response->body(), true);

    assertSame('ok', $payload['checks']['database']['status'] ?? '');
    assertTrue(($payload['checks']['database']['ms'] ?? -1) >= 0, 'время ответа базы посчитано');
    assertTrue(isset($payload['checks']['disk']['status']), 'диск проверяется отдельно');
    assertTrue(isset($payload['checks']['queue']['status']), 'очередь проверяется отдельно');
});

test('api: ошибки приходят в едином формате', function (): void {
    $response = httpRequest('GET', '/api/v1/notes');

    $payload = json_decode($response->body(), true);

    assertTrue(isset($payload['error']['message']), 'у ошибки есть message');
    assertSame(401, (int) $payload['error']['status']);
});

test('права: реестр знает и свои права приложения', function (): void {
    assertTrue(Permission::known('notes.view'), 'право из config/permissions.php');
    assertTrue(Permission::known(Permission::USERS_MANAGE));
    assertFalse(Permission::known('такого.права.нет'));

    assertTrue(in_array('notes.view', Permission::user(), true), 'обычному пользователю раздел доступен');
    assertFalse(in_array(Permission::DATA_ALL, Permission::user(), true), 'чужие данные — нет');
});
