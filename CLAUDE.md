# CLAUDE.md — Proton, микрофреймворк на чистом PHP

Документ для Claude Code и разработчиков: назначение, архитектура, соглашения и правила
работы с кодом. Всё общение и документация по проекту — **на русском языке**.
Отвечать на запросы чётко и коротко, без лишней «воды». Вежливость можно отбросить.

## 1. Что это

Самостоятельный микрофреймворк на PHP 8.1+ **без composer и без единой внешней
зависимости**, из которого поднимается обычное веб-приложение: маршрутизация, ORM
(Active Record), миграции, авторизация с ролями и правами, готовая панель управления,
API с ключами, очередь задач с воркером и расписанием, почта, вебхуки, кэш, события,
загрузка файлов, консоль с генераторами и свой тестраннер.

Поставляется в двух частях: `framework/` — ядро (его меняют редко), всё остальное в
корне — готовый скелет приложения, который запускается сразу после установки.

Проект вырос из ядра почтового сервиса (mailer), но **самостоятелен**: общего кода нет,
и оглядываться на mailer не нужно.

Работает одинаково на Linux (продакшен: nginx + php-fpm) и Windows (разработка:
`php bin/proton serve`).

## 2. Жёсткие правила проекта

- **Никаких внешних зависимостей и composer.** Только PHP и стандартные расширения:
  `pdo_sqlite` / `pdo_mysql`, `openssl`, `mbstring`, `json`. Сеть — потоками
  (`stream_socket_client`), `curl` опционален (есть fallback).
- **Автозагрузка своя** — `framework/Autoload.php`: `Rsgrinko\Proton\` -> `framework/`,
  `App\Migrations\` -> `migrations/`, `App\` -> `app/`.
- **Строгая типизация**: каждый PHP-файл начинается с `declare(strict_types=1);`.
- **Только фигурные скобки.** Альтернативный синтаксис (`if (…): … endif;`) не
  используется нигде, включая шаблоны:
  ```php
  <?php foreach ($items as $item) { ?>
      <tr>…</tr>
  <?php } ?>
  ```
- **Секреты не хранятся в коде.** Всё чувствительное — в `.env` (в репозитории только
  `.env.example`). Пароли — хешами (`password_hash`), чужие секреты — шифрованием
  (`Auth\Crypto`, мастер-ключ `APP_KEY`).
- **Комментарии, сообщения об ошибках и документация — на русском.**
- Пути строятся от константы `APP_ROOT`, разделители — прямые слэши.
- В коде, документации, комментариях и истории коммитов **не должно быть следов того,
  что что-то писал ИИ**: ни упоминаний ассистентов, ни соавторства, ни служебных
  подписей, ни штампов вроде «сгенерировано», «AI», «assistant». В частности, в
  сообщениях коммитов и описаниях PR не бывает трейлеров `Co-Authored-By` и
  `Claude-Session`: автор кода один — владелец репозитория.
- Комментарии пишутся так, как их писал бы разработчик коллеге: по делу, без пересказа
  очевидного и без канцелярита. Коммиты — короткие однострочные сообщения на русском.

## 3. Структура каталогов

```
framework/            ядро, namespace Rsgrinko\Proton\
  Support/            Config, Env, Container, Str, Validator, Logger, Audit,
                      Diagnostics, ClientIp, IpAllowlist, RequestId, исключения
  Http/               Request, Response, Router, Route, Kernel, Controller,
                      Middleware/ (ApiKey, Authenticate, Can, Guest, Install,
                      Throttle, VerifyCsrf)
  Database/           Connection (sqlite|mysql), Query/Builder, Model/ (Active Record
                      со связями), Schema/ (Blueprint, типы, шаги), Migration,
                      Migrator, Lock
  Auth/               Auth, Password, Crypto, Csrf, Devices
  Access/             Permission (реестр прав), Role-логика, Scope, Viewer
  Models/             модели ядра: User, Role, ApiToken, RememberToken, UserSession,
                      AuthToken, AuditEntry, Setting, Webhook, WebhookDelivery
  Queue/              Queue, Job, Worker, Scheduler
  Mail/               Mail, Message, Mime, Drivers/ (mail, smtp, mailer, log, null),
                      SendMailJob
  Webhooks/           Webhooks (реестр событий и рассылка), DeliverWebhookJob
  Install/            Installer — общий движок установки для консоли и веба
  Cache/  Events/  Files/  RateLimit/  View/  Console/

app/                  код приложения, namespace App\
  Controllers/        Web/, Admin/, Api/
  Models/             свои модели (Note — демонстрационная)
  Jobs/               свои задачи очереди

config/               config.php (читает .env), menu.php, permissions.php,
                      events.php, webhooks.php, schedule.php, commands.php
routes/               web.php, admin.php, api.php
resources/views/      шаблоны (layouts, auth, admin, notes, install, mail, errors)
migrations/           миграции: 20260912100000_create_core_tables.php
stubs/                заготовки генераторов, stubs/crud/ — заготовки раздела целиком
tests/                свой раннер (run.php) и тесты
docs/                 документация: START, DATABASE, MIGRATIONS, ROUTING, ACCESS,
                      QUEUE, MAIL, WEBHOOKS, CONSOLE, TESTS, DEPLOY,
                      STATUS (на чём остановились)
public/index.php      единственная точка входа
bin/proton            консольная утилита
var/                  runtime: база SQLite, логи, кэш, загруженные файлы
```

## 4. Установка

Ставит приложение **установщик**, и он один: `php bin/proton install` в консоли и
`/install` в браузере. Движок общий — `Install\Installer`, мастер только спрашивает
и показывает; иначе два установщика неизбежно разъезжаются.

Пока приложение не установлено (нет ключа, схемы или ни одного пользователя), любой
адрес уводит на `/install`; как только администратор заведён, установщик закрывается
и уводит на главную. Отдельной страницы первого запуска нет.

## 5. Ключевые решения

**Маршруты** живут в `routes/*.php`, а не в ядрах. Обработчик — пара
`[Контроллер::class, 'метод']`, объект создаётся только при совпадении. Аргументы метода
роутер подставляет по именам: `Request` — сам запрос, `{id}` — из адреса, остальное —
атрибуты от прослоек (`$viewer`, `$scope`). Числовым параметрам обязательно ограничение
`{id:\d+}`, иначе `/notes/new` попадёт в `/notes/{id}`.

**Адреса нигде не пишутся строкой**: во вьюхах и контроллерах —
`View::route('notes.show', ['id' => 5])`, в остальном коде — `Router::url()`.

**Доступ** задаётся прослойкой у группы: `auth`, `guest`, `install`, `can:право`,
`api`, `throttle:лимит,окно`. Право и область видимости — разные вещи:

- **право** (`Access\Permission`) решает, пускать ли в раздел или к действию;
  права живут в коде (свои — в `config/permissions.php`), в базе только роли;
- **область видимости** (`Access\Scope`) решает, чьи записи видно; уходит в SQL через
  `$scope->apply($query)`, поэтому чужая запись в контроллер не приходит вовсе.
  Снимает фильтр только право `data.all`.

**Контейнер** (`Support\Container`) собирает контроллеры и их зависимости; умеет только
классы `Rsgrinko\Proton\`/`App\`, общий `Connection` и значения по умолчанию.

**Формы** защищены от подделки: `<?= View::csrf() ?>` в каждой POST-форме, прослойка
`csrf` сверяет токен. Форма без поля получит 403. API это не касается — там ключ
в заголовке.

**Всё, что меняет данные**, попадает в журнал действий: `Audit::created|updated|deleted|
action()`. Секреты в журнал не пишутся, `Audit::between()` считает «поле => было/стало».

**Очередь общего назначения**: письмо — просто одна из задач (`SendMailJob`). Расписание
ведёт тот же воркер, cron не нужен. **После выкладки кода воркер нужно перезапускать** —
он держит классы в памяти (`php bin/proton worker:restart`).

**Почта**: драйвер выбирается настройкой `MAIL_DRIVER` (`mail`, `smtp`, `mailer`, `log`,
`null`), подменяется событием `mail.driver` — места отправки про способ не знают.

**Вебхуки** (`Webhooks`) — наружная сторона событий: подписка из панели получает
посылку POST с подписью `X-Proton-Signature` (HMAC-SHA256 от «время.тело» секретом
подписки). Подписаться можно только на событие из реестра — события живут в коде
(свои — в `config/webhooks.php`), в базе только подписки и журнал доставок. Реестр
повешен на шину событий строкой `Webhooks::subscribe()` в `config/events.php`,
поэтому `Events::fire()` достаточно. Доставляет очередь: повтор берёт тело из
журнала, чтобы подпись сходилась, а подписка, молчащая `WEBHOOKS_DISABLE_AFTER`
раз подряд, отключается сама. Посылки идут очередью `webhooks` — воркер умеет
несколько очередей через запятую (`worker --queue=default,webhooks`).

**Чужие HTTP-сервисы** — только через `Support\HttpClient` (curl с откатом на
потоки): проверку сертификата он не отключает, а путь к набору корневых берёт из
`HTTP_CA_BUNDLE`. Своих `curl_init` в коде больше быть не должно.

**Схему меняют только миграции**: файл на миграцию, имя `20260912100000_create_orders.php`,
класс по хвосту имени (`CreateOrders`). Таблица описывается `Blueprint`, а не строками
SQL; у каждой миграции честный `down()`. Накат идёт под блокировкой, шаг знает, что он
уже выполнен.

**Обе СУБД поддерживаются одним кодом**: диалект знает `Connection`. По умолчанию SQLite
(`var/app.sqlite`), MySQL включается через `DB_DRIVER=mysql`. Каждый именованный параметр
встречается в запросе один раз — MySQL работает без эмуляции подготовленных выражений и
повтор имени не принимает.

## 6. Соглашения по коду

- Классы — `PascalCase`, методы и свойства — `camelCase`, константы — `UPPER_SNAKE`.
- Один класс — один файл, путь соответствует namespace.
- Значения статусов и типов — константы классов, не «магические» строки.
- Исключения: `Support\ProtonException` — базовое.
- Ввод из HTTP всегда проходит `Validator`; в SQL — только подготовленные выражения.
- SQL живёт в моделях и репозиториях, а не в контроллерах.
- Методы чтения принимают область видимости и подмешивают её в `WHERE`, а не фильтруют
  после выборки.
- Новый раздел панели — значит и пункт меню, и право, и строка аудита, и пагинация.
- Разметка: списку — класс `list`, шапке — `head`, второстепенным колонкам — `hide-sm`,
  широкой таблице — обёртка `div.table-wrap`. Действие показывается только тому, кто
  может его выполнить.
- Логи — `var/log/app-ГГГГ-ММ-ДД.log`, запись всегда одной строкой.

## 7. Команды

```
php bin/proton install               установить приложение
php bin/proton migrate [--pretend]   применить миграции
php bin/proton migrate:status|migrate:rollback
php bin/proton serve                 встроенный сервер для разработки
php bin/proton test [--filter=]      тесты
php bin/proton make:crud <Имя> --fields="имя:тип:подпись,…"   раздел целиком
php bin/proton make:model|make:controller|make:migration|make:job|make:command|make:test
php bin/proton worker [--once] [--queue=default,webhooks]|worker:restart|schedule:run
php bin/proton queue:status|queue:retry|queue:purge
php bin/proton user:create|user:list|user:password|user:delete|role:list
php bin/proton key:create|key:list|key:revoke
php bin/proton mail:test <адрес>     пробное письмо
php bin/proton webhook:list [--events]  подписки или реестр событий
php bin/proton webhook:test <id>     пробная посылка подписчику
php bin/proton status                самопроверка
php bin/proton route:list|cache:clear|logs:purge|app:key|seed
```

Новая команда — класс в `app/Commands` (или `framework/Console/Commands` для ядра) плюс
строка в реестре `config/commands.php` (для ядра — в `Console\Application::COMMANDS`).
Справка собирается из самих команд. Печать — через `line()`, `ok()`, `fail()`, `table()`,
`pad()` (`str_pad` считает байты и ломает выравнивание на русских заголовках).

## 8. Тесты

Свой раннер: `php bin/proton test` (он же `php tests/run.php`), без PHPUnit. Тест —
функция `test()`, проверки — `assertSame()` и соседи. Предупреждения PHP приравнены
к ошибке.

**База для тестов всегда своя**: по умолчанию SQLite в памяти, настройки `DB_*` из
`.env` раннер не читает вовсе. MySQL включается только переменными `TEST_DB_*` и только
на отдельной базе; раннер не стартует, если имя совпало с боевым.

Помощники: `afterTests()` — уборка после прогона, `withConfig()` — настройки на время
теста, `withOwnDatabase()` — своя чистая база для тестов-хозяев, `testDatabaseFile()` —
база-файл для второго процесса.

`HttpTest` идёт на общей базе и обходит настоящие маршруты ядром — за собой нужно
прибирать. Подробности — `docs/TESTS.md`.

## 9. Работа с данными

База считается боевой: ничего не удаляем и не затираем по своей инициативе — ни в MySQL,
ни в SQLite, ни ради проверки. Заводить новое можно; после проверок свои записи убираем
точечно, по id, а не по маске. Миграции накатывать можно, но **откат на боевой базе —
крайняя мера**: он удаляет колонки вместе с данными.
