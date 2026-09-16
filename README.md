# Proton

Микрофреймворк на PHP 8.1+ без composer. Из расширений нужны `pdo_sqlite` или
`pdo_mysql`, `openssl`, `mbstring`, `json` — больше ничего.

Задача у него простая: быстро поднимать небольшие приложения, где есть вход,
роли, какая-то админка, немного фоновой работы и письма. Всё это уже написано,
остаётся писать свои разделы.

Зависимостей нет сознательно: проект переносится копированием каталога, ничего не
надо собирать, версии ничего не ломают. Обратная сторона очевидна — всё, что нужно,
написано здесь же и поддерживать это тоже придётся самому. Код ядра лежит в
`framework/`, он не прячется.

## Поднять у себя

```bash
php bin/proton install      # спросит название, базу, почту и первого админа
php bin/proton serve        # http://127.0.0.1:8000
```

Установщик в консоли и в браузере один и тот же. Пока приложение не установлено,
любой адрес уводит на `/install`: мастер проверяет окружение, пишет `.env`,
накатывает схему, заводит администратора — и после этого закрывается.

По умолчанию база — SQLite в `var/app.sqlite`, то есть настраивать нечего. MySQL
включается через `DB_DRIVER=mysql`, код от этого не меняется.

Убедиться, что всё на месте:

```bash
php bin/proton status       # окружение, база, ключи, очередь, почта, копии
php bin/proton test         # 183 теста
php bin/proton route:list   # карта адресов
php bin/proton help         # все команды
```

## Что уже есть

| | |
|---|---|
| Маршрутизация | группы, прослойки, именованные маршруты, аргументы по именам |
| База | SQLite и MySQL одним кодом, построитель запросов с кэшем результата, Active Record со связями, мягкое удаление, пагинация |
| Миграции | схема-билдер вместо строк SQL, накат под блокировкой, честный `down()` |
| Доступ | вход, регистрация, подтверждение почты, сброс пароля, «запомнить меня», сеансы и устройства, роли с правами, область видимости, вход под пользователем |
| Панель | пользователи, роли, ключи API, вебхуки, настройки, копии базы, отчёты, журнал действий, логи, состояние |
| API | ключи со скоупом прав и ограничением по адресам, лимит запросов, единый формат ошибок |
| Очередь | задачи с повторами и задержкой, цепочки, воркер, расписание внутри воркера |
| Почта | `mail()`, SMTP, внешний сервис по HTTP, запись в файл, заглушка для тестов |
| Вебхуки | подписки на события, подпись посылок, повторы, журнал доставок |
| Уведомления | лента в приложении и письма, адресация по праву |
| Копии базы | создание с проверкой, ротация, расписание, отправка на FTP, восстановление из консоли |
| Списки | фильтры, сортировка и постраничная навигация одним описанием, выгрузка и загрузка CSV |
| Показатели | отчёты за период, счётчики запросов, снимок для мониторинга, присмотр за порогами |
| Прочее | контейнер зависимостей, кэш, события, модельные события и наблюдатели, загрузка файлов, журнал действий, свой логгер, самодиагностика, режим обслуживания |
| Консоль | генераторы (вплоть до раздела целиком), обслуживание, пользователи, ключи, tinker (REPL) |
| Тесты | свой раннер без PHPUnit, 183 теста, база только своя |

## Чего нет

Чтобы не было разочарования на третий день:

- нет шаблонизатора со своим синтаксисом — шаблоны это обычные PHP-файлы;
- нет сборки фронтенда: CSS лежит в каркасе страницы, JS ровно столько, сколько
  нужно кнопке «скопировать»;
- нет очереди на Redis и вообще Redis — очередь и кэш живут в базе и файлах;
- нет мультиязычности: все тексты русские, гвоздями;
- нет событий по WebSocket и GraphQL;
- миграции не умеют менять колонку «на месте» в SQLite дальше того, что умеет сам
  SQLite.

Если что-то из этого нужно — проще взять большой фреймворк, чем достраивать это
здесь.

## Код

Маршруты лежат в `routes/`. Обработчик создаётся только при совпадении адреса:

```php
$router->group(['middleware' => ['csrf', 'auth']], function (Router $router): void {
    $router->get('/notes/{id:\d+}', [NotesController::class, 'show'])
        ->middleware('can:notes.view')
        ->name('notes.show');
});
```

Аргументы действия роутер подставляет по именам: `Request` — сам запрос, `{id}` —
из адреса, `Viewer` и `Scope` приходят от прослойки авторизации:

```php
public function show(int $id, Scope $scope): Response
{
    // Чужая запись сюда не доедет: область видимости ушла в SQL
    $note = $this->require($scope->apply(Note::query())->where('id', $id)->first(), 'notes.index');

    return $this->view('notes/show', ['note' => $note], (string) $note->title);
}
```

Модель:

```php
$notes = Note::query()->where('pinned', 1)->with('author')->orderBy('id', 'desc')->paginate(1, 25);

$note = Note::create(['title' => 'Заголовок', 'body' => 'Текст']);
$note->title = 'Другой';
$note->save();   // в UPDATE уйдут только изменённые поля
```

Очередь общего назначения, письмо — одна из задач:

```php
Queue::push(RebuildNoteSlugsJob::class, ['from' => 0], 60);   // через минуту
Message::to($user->email)->subject('Отчёт')->view('mail/report', [...])->queue();

// Шаг после успеха предыдущего, без проверок в вызывающем коде
Queue::chain([[ExportJob::class, ['kind' => 'users']], [NotifyExportReadyJob::class]]);
```

Событие можно отдать наружу подписчику или человеку внутри — и то и другое идёт
через очередь:

```php
Webhooks::register('order.paid', 'Заказ оплачен', 'Заказы');   // config/webhooks.php
Events::fire('order.paid', ['order' => $order]);               // уедет подписчикам само

Notify::send($user, new OrderPaid($order));                     // лента и письмо
Notify::toPermission(Permission::SYSTEM_MANAGE, $notification);  // всем, у кого право
```

Настройки, которые меняют на ходу, объявляются в коде, а значения лежат в базе
поверх `.env`:

```php
Settings::register('shop.currency', 'Валюта', Settings::STRING, 'Магазин');

$currency = Config::get('shop.currency');   // читается как обычная конфигурация
```

Раздел целиком — модель, миграция, контроллер, три шаблона и тест — пишет генератор:

```bash
php bin/proton make:crud Order --fields="number:string:Номер,total:int:Сумма,paid:bool:Оплачен"
```

Маршруты, право и пункт меню он печатает готовыми кусками, а не правит файлы сам:
так видно, что именно подключается.

## Что где

```
framework/            ядро, namespace Rsgrinko\Proton\ — меняется редко
  Support/            Config, Env, Settings, Container, Str, Validator, Logger,
                      Audit, Diagnostics, HttpClient, ClientIp, RequestId,
                      Filters, Csv, Export, Import, Reports, Metrics, Monitor
  Http/               Request, Response, Router, Route, Kernel, Controller, Middleware/
  Database/           Connection (sqlite|mysql), Query/Builder, Model/ (Active Record),
                      Schema/ (Blueprint), Migration, Migrator, Lock
  Auth/               Auth, Password, Crypto, Csrf, Devices
  Access/             Permission (реестр прав), Scope, Viewer
  Models/             User, Role, ApiToken, UserSession, AuditEntry, Setting,
                      Webhook, WebhookDelivery, UserNotification
  Queue/              Queue, Job, Worker, Scheduler
  Mail/               Message, Mime, Drivers/ (mail, smtp, mailer, log, null)
  Webhooks/           реестр событий, рассылка, доставка с повторами
  Notifications/      Notification, Notify (лента и письмо)
  Backup/             копии базы: создание, проверка, ротация, отправка на FTP
  Cache/  Events/  Files/  RateLimit/  View/  Console/  Install/

app/                  код приложения, namespace App\
  Controllers/        Web/, Admin/, Api/
  Models/             свои модели (Note — демонстрационная)
  Jobs/               свои задачи очереди

config/               config.php и реестры: menu, permissions, settings,
                      webhooks, events, schedule, commands, services
routes/               web.php, admin.php, api.php
resources/views/      шаблоны; свой файл перекрывает шаблон ядра
migrations/           миграции
stubs/                заготовки генераторов
tests/                свой раннер и тесты
var/                  база SQLite, логи, кэш, загрузки, копии
public/index.php      единственная точка входа
bin/proton            консоль
```

## Панель

Каждый раздел закрыт своим правом, всё, что меняет данные, попадает в журнал
действий.

Права объявлены в коде, в базе лежат только роли: иначе после нового раздела
администратор остался бы без доступа к нему. Право решает, пускать ли в раздел;
область видимости — чьи записи показывать. Второе уходит в SQL, поэтому чужая
запись в контроллер не попадает вовсе.

## Консоль

```bash
php bin/proton down [--message=] [--allow=] [--retry=] | up
php bin/proton migrate | migrate:status | migrate:rollback
php bin/proton worker [--once] [--queue=default,webhooks] | worker:restart | schedule:run
php bin/proton queue:status | queue:retry | queue:purge
php bin/proton user:create | user:list | user:password | role:list
php bin/proton key:create | key:list | key:revoke
php bin/proton mail:test you@example.com
php bin/proton webhook:list [--events] | webhook:test <id>
php bin/proton backup:create | backup:list [--check] | backup:restore <файл> --force | backup:ship <файл>
php bin/proton make:crud | make:model | make:controller | make:migration | make:job | make:command | make:test
php bin/proton status | route:list | cache:clear | logs:purge | app:key | seed
```

Воркер после выкладки кода нужно перезапускать: он держит в памяти и классы, и
настройки.

Без запущенного воркера очередь копится, письма не уходят, расписание не идёт.
Держать его — задача systemd, не cron:

```ini
# /etc/systemd/system/proton-worker.service
[Unit]
Description=Proton worker
After=network.target mysql.service

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/proton
ExecStart=/usr/bin/php /var/www/proton/bin/proton worker
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

```bash
systemctl enable --now proton-worker
```

Несколько очередей разными процессами, вариант без systemd (cron) — `docs/DEPLOY.md`.

## Документация

| Файл | О чём |
|------|-------|
| [docs/START.md](docs/START.md) | установка, первый раздел, как проходит запрос |
| [docs/DATABASE.md](docs/DATABASE.md) | подключение, построитель запросов, модели и связи |
| [docs/MIGRATIONS.md](docs/MIGRATIONS.md) | миграции, схема-билдер, откат |
| [docs/ROUTING.md](docs/ROUTING.md) | маршруты, прослойки, контроллеры, шаблоны |
| [docs/ACCESS.md](docs/ACCESS.md) | вход, права, роли, область видимости, ключи API |
| [docs/CONTAINER.md](docs/CONTAINER.md) | контейнер: привязки, singleton, сборка аргументов |
| [docs/LISTS.md](docs/LISTS.md) | фильтры и сортировка списков, выгрузка и загрузка CSV |
| [docs/METRICS.md](docs/METRICS.md) | отчёты, показатели работы, присмотр за порогами |
| [docs/QUEUE.md](docs/QUEUE.md) | очередь, воркер, расписание, события, кэш |
| [docs/MAIL.md](docs/MAIL.md) | письма и драйверы, в том числе внешний сервис |
| [docs/WEBHOOKS.md](docs/WEBHOOKS.md) | подписки на события, подпись посылок, повторы |
| [docs/NOTIFICATIONS.md](docs/NOTIFICATIONS.md) | уведомления: лента и письма |
| [docs/SETTINGS.md](docs/SETTINGS.md) | настройки из панели поверх `.env` |
| [docs/BACKUP.md](docs/BACKUP.md) | копии базы, проверка, расписание, восстановление |
| [docs/CONSOLE.md](docs/CONSOLE.md) | команды и генераторы |
| [docs/TESTS.md](docs/TESTS.md) | как писать и гонять тесты |
| [docs/DEPLOY.md](docs/DEPLOY.md) | nginx, php-fpm, systemd, обслуживание |
| [docs/STATUS.md](docs/STATUS.md) | на чём остановились и что дальше |
