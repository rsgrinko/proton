# Proton

Микрофреймворк на чистом PHP 8.1+. Без composer, без единой внешней зависимости —
только PHP и стандартные расширения. Скачал, запустил установщик, работаешь.

Внутри: маршрутизация с прослойками, Active Record со связями и миграциями, роли
с правами и областью видимости, панель управления, API с ключами, очередь задач с
воркером и расписанием, почта с пятью драйверами, вебхуки, уведомления, резервные
копии, настройки через панель, консоль с генераторами и свой тестраннер. 151 тест,
все на отдельной базе.

Работает одинаково на Linux (nginx + php-fpm) и на Windows
(`php bin/proton serve`).

## Почему без composer

Потому что так проект переносится целиком: скопировал каталог — он работает.
Нет разъехавшихся версий, нет `vendor` на 80 мегабайт, нет «обновите composer,
иначе не соберётся». Цена известна: всё, что нужно, написано здесь же. Зато и
ломаться нечему, кроме своего кода.

## Старт

```bash
php bin/proton install      # спросит название, базу, почту и первого админа
php bin/proton serve        # http://127.0.0.1:8000
```

Установщик один и тот же в консоли и в браузере: пока приложение не установлено,
любой адрес уводит на `/install`, мастер показывает проверки окружения, пишет
`.env`, накатывает схему и вводит вас в панель. Как только админ заведён,
установщик закрывается.

Убедиться, что всё на месте:

```bash
php bin/proton status       # окружение, база, ключи, очередь, почта, копии
php bin/proton test         # 151 тест, база в памяти
php bin/proton route:list   # карта адресов
php bin/proton help         # все команды
```

## Что где

```
framework/            ядро, namespace Rsgrinko\Proton\ — меняется редко
  Support/            Config, Env, Settings, Container, Str, Validator, Logger,
                      Audit, Diagnostics, HttpClient, ClientIp, RequestId
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
  Backup/             копии базы: создание, проверка, ротация
  Cache/  Events/  Files/  RateLimit/  View/  Console/  Install/

app/                  ваш код, namespace App\
  Controllers/        Web/, Admin/, Api/
  Models/             свои модели (Note — демонстрационная)
  Jobs/               свои задачи очереди

config/               config.php и реестры: menu, permissions, settings,
                      webhooks, events, schedule, commands
routes/               web.php, admin.php, api.php
resources/views/      шаблоны; свой файл перекрывает шаблон ядра
migrations/           миграции
stubs/                заготовки генераторов
tests/                свой раннер и тесты
var/                  база SQLite, логи, кэш, загрузки, копии
public/index.php      единственная точка входа
bin/proton            консоль
```

## Как это выглядит в коде

Маршрут — в `routes/`, а не в аннотациях. Обработчик создаётся, только если
адрес совпал:

```php
$router->group(['middleware' => ['csrf', 'auth']], function (Router $router): void {
    $router->get('/notes/{id:\d+}', [NotesController::class, 'show'])
        ->middleware('can:notes.view')
        ->name('notes.show');
});
```

Аргументы метода роутер подставляет по именам: `Request` — сам запрос, `{id}` —
из адреса, `Scope` и `Viewer` — от прослоек:

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
$note->save();
```

Очередь — общего назначения, письмо просто одна из задач:

```php
Queue::push(RebuildSlugsJob::class, ['from' => 0], 60);        // через минуту
Message::to($user->email)->subject('Отчёт')->view('mail/report', [...])->queue();
```

Событие можно отправить наружу подписчику (вебхук) и внутрь человеку
(уведомление) — оба механизма опираются на ту же очередь:

```php
Webhooks::register('order.paid', 'Заказ оплачен', 'Заказы');   // config/webhooks.php
Events::fire('order.paid', ['order' => $order]);               // уедет подписчикам сам

Notify::send($user, new OrderPaid($order));                    // лента + письмо
Notify::toPermission(Permission::SYSTEM_MANAGE, $notification); // всем, у кого право
```

Настройка, которую админ меняет на ходу, объявляется в коде, а её значение живёт
в базе поверх `.env`:

```php
Settings::register('shop.currency', 'Валюта', Settings::STRING, 'Магазин');

$currency = Config::get('shop.currency');   // читается как обычная конфигурация
```

Раздел целиком — модель, миграция, контроллер, три шаблона и тест — делает
генератор:

```bash
php bin/proton make:crud Order --fields="number:string:Номер,total:int:Сумма,paid:bool:Оплачен"
```

## Панель

Обзор, пользователи, роли, ключи API, вебхуки, настройки, копии базы, журнал
действий, логи, состояние. Каждый раздел закрыт своим правом, всё, что меняет
данные, попадает в журнал.

Права живут в коде, в базе только роли — иначе новый раздел оказался бы
недоступен даже администратору. Право отвечает на вопрос «пускать ли в раздел»,
область видимости — «чьи записи видно»; второе уходит в SQL, поэтому чужая
запись в контроллер не приходит вовсе.

## Консоль

```bash
php bin/proton migrate | migrate:status | migrate:rollback
php bin/proton worker [--once] [--queue=default,webhooks] | worker:restart | schedule:run
php bin/proton queue:status | queue:retry | queue:purge
php bin/proton user:create | user:list | user:password | role:list
php bin/proton key:create | key:list | key:revoke
php bin/proton mail:test you@example.com
php bin/proton webhook:list [--events] | webhook:test <id>
php bin/proton backup:create | backup:list [--check] | backup:restore <файл> --force
php bin/proton make:crud | make:model | make:controller | make:migration | make:job | make:command | make:test
php bin/proton status | route:list | cache:clear | logs:purge | app:key | seed
```

## Документация

| Файл | О чём |
|------|-------|
| [docs/START.md](docs/START.md) | установка, первый раздел, структура приложения |
| [docs/DATABASE.md](docs/DATABASE.md) | подключение, построитель запросов, модели и связи |
| [docs/MIGRATIONS.md](docs/MIGRATIONS.md) | миграции, схема-билдер, откат |
| [docs/ROUTING.md](docs/ROUTING.md) | маршруты, прослойки, контроллеры, шаблоны |
| [docs/ACCESS.md](docs/ACCESS.md) | вход, права, роли, область видимости, ключи API |
| [docs/QUEUE.md](docs/QUEUE.md) | очередь, воркер, расписание, события, кэш |
| [docs/MAIL.md](docs/MAIL.md) | письма и драйверы, в том числе внешний сервис |
| [docs/WEBHOOKS.md](docs/WEBHOOKS.md) | подписки на события, подпись посылок, повторы |
| [docs/NOTIFICATIONS.md](docs/NOTIFICATIONS.md) | уведомления: лента и письма |
| [docs/BACKUP.md](docs/BACKUP.md) | копии базы, проверка, расписание, восстановление |
| [docs/CONSOLE.md](docs/CONSOLE.md) | команды и генераторы |
| [docs/TESTS.md](docs/TESTS.md) | как писать и гонять тесты |
| [docs/DEPLOY.md](docs/DEPLOY.md) | nginx, php-fpm, systemd, обслуживание |
| [docs/STATUS.md](docs/STATUS.md) | на чём остановились и что дальше |

## Правила, которые стоит соблюдать

- Никаких внешних зависимостей и composer. Только PHP и `pdo_sqlite`/`pdo_mysql`,
  `openssl`, `mbstring`, `json`.
- `declare(strict_types=1);` в каждом файле, только фигурные скобки (в шаблонах
  тоже), один класс — один файл.
- Комментарии, сообщения об ошибках и документация — на русском.
- Секреты только в `.env`. Пароли — хешами, чужие секреты — шифрованием (`APP_KEY`).
- Ввод из HTTP проходит `Validator`, в SQL — только подготовленные выражения, SQL
  живёт в моделях, а не в контроллерах.
- Схему меняют только миграции, и у каждой есть рабочий `down()`.
- К чужим HTTP-сервисам ходим через `Support\HttpClient`: проверку сертификата он
  не отключает.
- После выкладки кода воркер нужно перезапускать (`worker:restart`) — он держит
  классы и настройки в памяти.
