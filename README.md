# Proton

Микрофреймворк на чистом PHP 8.1+ **без composer и без единой внешней зависимости**.
Из коробки: маршрутизация, ORM (Active Record), миграции, авторизация с ролями и правами,
готовая панель управления, API с ключами, очередь задач с воркером и расписанием, почта,
кэш, события, загрузка файлов, консольные команды с генераторами кода и свой тестраннер.

Работает одинаково на Linux (nginx + php-fpm) и Windows (`php bin/proton serve`).

## Быстрый старт

```bash
php bin/proton install      # .env, ключ, база, схема, администратор
php bin/proton serve        # http://127.0.0.1:8000
```

Установщик спрашивает название, адрес, базу, почту и первого администратора. То же
самое умеет браузер: пока приложение не установлено, любой адрес уводит на `/install`,
и мастер сначала показывает проверки окружения, а в конце сам вводит вас в панель.
Движок у обоих один, поэтому консоль и веб ставят систему одинаково. Как только
администратор заведён, установщик закрывается.

Проверить, что всё на месте:

```bash
php bin/proton status       # окружение, база, ключи, очередь, почта
php bin/proton test         # 109 тестов, своя база в памяти
php bin/proton route:list   # карта адресов
php bin/proton help         # все команды
```

## Что где лежит

```
framework/            ядро, namespace Rsgrinko\Proton\ — его меняют редко
  Support/            Config, Env, Container, Str, Validator, Logger, Audit,
                      Diagnostics, ClientIp, IpAllowlist, RequestId, исключения
  Http/               Request, Response, Router, Route, Kernel, Controller, Middleware/
  Database/           Connection (sqlite|mysql), Query/Builder, Model/ (Active Record
                      со связями), Schema/ (Blueprint, типы, шаги), Migration, Migrator, Lock
  Auth/               Auth, Password, Crypto, Csrf, Devices
  Access/             Permission (реестр прав), Role-логика, Scope, Viewer
  Models/             модели ядра: User, Role, ApiToken, RememberToken, UserSession,
                      AuthToken, AuditEntry, Setting
  Queue/              Queue, Job, Worker, Scheduler
  Mail/               Mail, Message, Mime, Drivers/ (mail, smtp, mailer, log, null), SendMailJob
  Cache/  Events/  Files/  RateLimit/  View/  Console/

app/                  код приложения, namespace App\
  Controllers/        Web/, Admin/, Api/
  Models/             свои модели (Note — демонстрационная)
  Jobs/               свои задачи очереди

config/               config.php, menu.php, permissions.php, events.php, schedule.php, commands.php
routes/               web.php, admin.php, api.php
resources/views/      шаблоны приложения (перекрывают шаблоны ядра)
migrations/           миграции: 20260912100000_create_core_tables.php
stubs/                заготовки для генераторов
tests/                свой раннер и тесты
docs/                 документация
public/               index.php — единственная точка входа
var/                  runtime: база SQLite, логи, кэш, загруженные файлы
bin/proton            консольная утилита
```

## Пять минут по основному

**Маршрут** (`routes/web.php`):

```php
$router->group(['middleware' => ['csrf', 'auth']], function (Router $router): void {
    $router->get('/notes/{id:\d+}', [NotesController::class, 'show'])
        ->middleware('can:notes.view')
        ->name('notes.show');
});
```

**Контроллер**:

```php
final class NotesController extends Controller
{
    public function show(int $id, Scope $scope): Response
    {
        $note = $this->require($scope->apply(Note::query())->where('id', $id)->first(), 'notes.index');

        return $this->view('notes/show', ['note' => $note], (string) $note->title);
    }
}
```

**Модель**:

```php
$notes = Note::query()->where('pinned', 1)->with('author')->orderBy('id', 'desc')->paginate(1, 25);

$note = Note::create(['title' => 'Заголовок', 'body' => 'Текст']);
$note->title = 'Другой';
$note->save();
```

**Очередь** (задачи любые, не только письма):

```php
Queue::push(RebuildNoteSlugsJob::class, ['from' => 0], 60);   // с задержкой в минуту
Message::to($user->email)->subject('Отчёт')->view('mail/report', [...])->queue();
```

**Почта**: драйвер выбирается настройкой `MAIL_DRIVER` — `mail` (по умолчанию), `smtp`,
`mailer` (внешний почтовый сервис по HTTP API), `log`, `null`. Способ отправки можно
подменить, не трогая места отправки, — событием `mail.driver`.

## Документация

| Файл | О чём |
|------|-------|
| [docs/START.md](docs/START.md) | установка, первый раздел, структура приложения |
| [docs/DATABASE.md](docs/DATABASE.md) | подключение, построитель запросов, модели и связи |
| [docs/MIGRATIONS.md](docs/MIGRATIONS.md) | миграции, схема-билдер, откат |
| [docs/ROUTING.md](docs/ROUTING.md) | маршруты, прослойки, контроллеры, шаблоны |
| [docs/ACCESS.md](docs/ACCESS.md) | вход, регистрация, права, роли, область видимости, API-ключи |
| [docs/QUEUE.md](docs/QUEUE.md) | очередь, воркер, расписание, события, кэш |
| [docs/MAIL.md](docs/MAIL.md) | письма и драйверы, в том числе внешний сервис |
| [docs/CONSOLE.md](docs/CONSOLE.md) | команды и генераторы |
| [docs/TESTS.md](docs/TESTS.md) | как писать и гонять тесты |
| [docs/DEPLOY.md](docs/DEPLOY.md) | nginx, php-fpm, systemd, обслуживание |
| [docs/STATUS.md](docs/STATUS.md) | на чём остановились и что дальше |

## Правила проекта

- **Никаких внешних зависимостей и composer.** Только PHP и стандартные расширения:
  `pdo_sqlite` / `pdo_mysql`, `openssl`, `mbstring`, `json`.
- Автозагрузка своя — `framework/Autoload.php`.
- `declare(strict_types=1);` в каждом файле, только фигурные скобки, один класс — один файл.
- Комментарии, сообщения об ошибках и документация — на русском.
- Секреты только в `.env`; в базе пароли — хешами, чужие секреты — шифрованием (`APP_KEY`).
- Всё, что приходит от пользователя, проходит `Validator`; в SQL — только подготовленные выражения.
- **После выкладки кода воркер нужно перезапускать** (`php bin/proton worker:restart`):
  он держит загруженные классы в памяти.
