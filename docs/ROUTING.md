# Маршруты, прослойки, контроллеры и шаблоны

## Маршруты

Живут в `routes/` (файлы перечислены в `config/config.php`, ключ `routes`). Файл
возвращает функцию, принимающую роутер.

```php
return static function (Router $router): void {
    $router->group(['prefix' => '/admin', 'middleware' => ['csrf', 'auth']], function (Router $router): void {
        $router->get('/users', [UsersController::class, 'index'])
            ->middleware('can:users.manage')
            ->name('admin.users');

        $router->post('/users/{id:\d+}', [UsersController::class, 'update']);
    });
};
```

- Обработчик — пара `[Контроллер::class, 'метод']` или замыкание. Класс создаётся,
  только если маршрут совпал, и получает зависимости из контейнера.
- **Числовым параметрам ставится ограничение** — `{id:\d+}`, иначе `/users/new`
  попадёт в маршрут `/users/{id}`.
- У маршрута должно быть имя: адреса нигде не пишутся строкой, только
  `View::route('admin.users')` во вьюхах и `Router::url()` в остальном коде.
  Поменялся путь — правится одна строка в файле маршрутов.
- Группы вкладываются: префиксы складываются, прослойки накапливаются.

Посмотреть, что получилось: `php bin/proton route:list` (можно с поиском по строке).

## Прослойки

Зарегистрированы в ядре (`framework/Http/Kernel.php`), задаются именем у группы
или маршрута. Аргумент передаётся через двоеточие.

| Имя | Что делает |
|-----|-----------|
| `csrf` | сверяет токен формы при любом изменяющем запросе |
| `auth` | пускает только вошедших, кладёт в запрос `user`, `viewer`, `scope` |
| `guest` | только для не вошедших (вход, регистрация) |
| `can:право` | проверяет право; несколько через черту — «хватит любого» |
| `api` | ключ API в заголовке `Authorization: Bearer …` |
| `throttle:120,60` | не больше 120 запросов за 60 секунд |
| `setup` | страница первого запуска, пока в базе нет пользователей |

Своя прослойка — класс с методом `__invoke(Request $request, callable $next): Response`,
зарегистрированный по имени. Ядро трогать не нужно: `$router` в файле маршрутов —
тот же самый роутер, что и в `Kernel`, поэтому регистрация ложится в начало
`routes/web.php` (или своего файла из `config/config.php`), рядом с маршрутами,
которые её используют:

```php
final class AuditMiddleware
{
    public function __invoke(Request $request, callable $next): Response
    {
        $started = microtime(true);

        $response = $next($request);

        (new Logger('audit'))->info('Запрос', [
            'path'   => $request->path,
            'ms'     => round((microtime(true) - $started) * 1000, 1),
            'status' => $response->status(),
        ]);

        return $response;
    }
}
```

```php
return static function (Router $router): void {
    $router->middleware('audit', new AuditMiddleware());

    $router->group(['prefix' => '/admin', 'middleware' => ['csrf', 'auth', 'audit']], function (Router $router): void {
        // …
    });
};
```

Прослойка с аргументом (`audit:orders`) получает его третьим параметром
`__invoke(Request $request, callable $next, string $argument = '')` — так
собран `can:право` и `throttle:120,60`.

Право проверяется прослойкой, а не в контроллере: так забыть его можно только
вместе со всей группой.

## Контроллеры

Наследуются от `Rsgrinko\Proton\Http\Controller`, зависимости получают через
конструктор. Аргументы действия роутер подставляет по именам и типам:

- `Request $request` — сам запрос;
- `{id}` из адреса (приводится к типу аргумента);
- `user`, `viewer`, `scope`, `token` — то, что положили прослойки.

```php
final class NotesController extends Controller
{
    public function store(Request $request, Viewer $viewer): Response
    {
        $data = $this->validate($request, [
            'title' => 'required|max:191',
            'body'  => 'nullable|max:20000',
        ], ['title' => 'Название']);

        $note = new Note($data);
        $note->setAttribute('user_id', $viewer->id());
        $note->save();

        Audit::created('note', $note->id(), 'заметка «' . $note->title . '»');

        $this->flash('Заметка сохранена');

        return $this->redirect('notes.show', ['id' => $note->id()]);
    }
}
```

Помощники контроллера: `view()`, `bare()` (страница без шапки), `redirect()`,
`back()`, `flash()`, `validate()`, `require()` (запись обязана быть), `page()`,
`perPage()`.

Проверка не прошла — контроллер ничего не ловит: ядро само вернёт человека на форму
с заполненными полями и сообщениями, а API отдаст 422 со списком ошибок.

## Ответы

```php
Response::html($html);
Response::json(['data' => …]);
Response::error('Сообщение', 404);
Response::redirect(View::route('home'));
Response::download($content, 'файл.csv');
Response::text('строка');
```

## Шаблоны

Обычные PHP-файлы: ни своего языка, ни компиляции, ни кэша, который надо чистить
после выкладки. Ищутся сначала в `resources/views`, потом в `framework/View/views` —
любой шаблон ядра (каркас, страницы ошибок, пагинация) перекрывается своим файлом
с тем же именем.

```php
<?= View::e($note->title) ?>                     <!-- экранирование обязательно -->
<?= View::csrf() ?>                              <!-- в каждой изменяющей форме -->
<a href="<?= View::e(View::route('notes.show', ['id' => $note->id()])) ?>">…</a>
<?= View::date($note->raw('created_at')) ?>      <!-- 12.09.2026 18:40 -->
<?= View::ago($note->raw('updated_at')) ?>       <!-- 5 мин назад -->
<?php if (View::can('notes.manage')) { ?>…<?php } ?>
<?= View::partial('pagination', ['route' => 'notes.index', 'page' => …, 'pages' => …, 'params' => …]) ?>
```

Соглашения по разметке (их держит и вёрстка каркаса):

- **только фигурные скобки** — `<?php foreach (…) { ?>` … `<?php } ?>`, без `endforeach`;
- **действие показывается только тому, кто может его выполнить**: кнопки и ссылки
  прячутся по `View::can()`, но доступ закрывает прослойка — разметка лишь не дразнит;
- таблица-список получает класс `list`, а строка-шапка — `head`: на телефоне список
  разворачивается в карточки, шапка скрывается. Забыли `head` — на телефоне пропадёт
  первая запись;
- второстепенные колонки помечаются `hide-sm`;
- широкая таблица лежит в `div.table-wrap`, иначе она распирает страницу вместо
  того, чтобы прокручиваться.
