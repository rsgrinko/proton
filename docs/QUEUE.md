# Очередь, расписание, события и кэш

Четыре механизма, которые работают вместе: долгую работу уносит очередь,
повторяющуюся — расписание, точки расширения даёт `Events`, а `Cache` не даёт
считать одно и то же дважды.

## Очередь

Очередь общего назначения: письмо — просто одна из задач, а не смысл очереди.

```php
use Rsgrinko\Proton\Queue\Queue;

Queue::push(RebuildNoteSlugsJob::class, ['from' => 0]);          // сейчас
Queue::push(RebuildNoteSlugsJob::class, ['from' => 0], 60);      // через минуту
Queue::push(ReportJob::class, ['month' => '2026-09'], 0, 'heavy'); // в очередь heavy
```

Задача — класс в `app/Jobs`, наследник `Job`. Обязателен только `handle()`:

```php
final class ReportJob extends Job
{
    public function attempts(): int { return 3; }          // сколько раз пробовать
    public function backoff(int $attempt): int { return $attempt * 60; } // пауза перед повтором
    public function queue(): string { return 'heavy'; }    // своя очередь по умолчанию

    public function handle(array $payload): void
    {
        // работа; исключение отсюда означает «не получилось, повторить»
    }

    public function failed(array $payload, string $error): void
    {
        // попытки кончились — сюда, чтобы сообщить или прибрать за собой
    }
}
```

Заготовку делает генератор: `php bin/proton make:job ReportJob`.

Статусы задачи: `queued` -> `running` -> `done` | `failed`. Упавшая задача
возвращается в `queued` с отложенным `available_at`, пока не кончились попытки;
после этого — `failed` и вызов `failed()`.

Задача, которая не помещается в один заход, ставит себе продолжение сама —
так `RebuildNoteSlugsJob` обходит таблицу порциями, не занимая воркер часами и
переживая перезапуск.

### Воркер

```bash
php bin/proton worker                  # демон: разбирает очередь и ведёт расписание
php bin/proton worker --once           # один круг и выход (для cron)
php bin/proton worker --queue=heavy    # только именованная очередь
php bin/proton worker --queue=default,webhooks   # несколько: левая разбирается первой
php bin/proton worker:restart          # доработает круг и выйдет, служба поднимет заново
```

Воркер держит загруженные классы в памяти, поэтому **после выкладки кода его нужно
перезапускать** — иначе php-fpm уже отвечает по-новому, а задачи выполняются старым
кодом. Ctrl+C и `systemctl stop` он понимает по-хорошему: доделывает текущую задачу
и выходит, а не обрывается на середине.

Между кругами воркер делает уборку (раз в `QUEUE_MAINTENANCE_INTERVAL`): возвращает
зависшие задачи, чистит выполненные старше `QUEUE_KEEP_DAYS`, счётчики лимитов,
просроченный кэш, токены «запомнить меня», старые сеансы, журнал действий, журнал
доставок вебхуков, прочитанные уведомления и логи.
Приложению об этом заботиться не нужно.

### Разбор полётов

```bash
php bin/proton queue:status            # сколько в очереди и что упало
php bin/proton queue:status --failed   # список упавших с текстом ошибки
php bin/proton queue:retry 42          # вернуть в очередь одну
php bin/proton queue:retry --failed    # вернуть все упавшие
php bin/proton queue:purge --days=7    # удалить выполненные старше срока
```

## Расписание

Cron не нужен: расписание ведёт тот же воркер. Задачи объявляются в
`config/schedule.php` — все на виду, искать по приложению нечего.

```php
Scheduler::every(300, 'cache:cleanup', static fn () => Cache::cleanup());
Scheduler::dailyAt('04:00', 'report', static fn () => (new DailyReport())->send());
```

Задачи ядра объявляются до файла приложения: сейчас это ежедневная копия базы,
если задано `BACKUP_SCHEDULE` (см. [BACKUP.md](BACKUP.md)). Задача приложения с тем же
именем заменяет её.

Имя задачи — её ключ: отметка о последнем запуске лежит в `settings`, поэтому
перезапуск воркера не превращается в повторный прогон всего расписания. Упавшая
задача пишется в лог и не роняет остальные.

Там, где воркера-демона нет, тот же круг делает cron раз в минуту:

```bash
php bin/proton schedule:run          # выполнить то, чему пора
php bin/proton schedule:run --list   # что объявлено и когда пойдёт
```

## События

Точки расширения, чтобы не править ядро. Подписки собраны в `config/events.php`.

```php
Events::listen('user.registered', static function (array $payload): void {
    // $payload['user'] — только что заведённый пользователь
}, 10); // приоритет: больше — раньше

Events::fire('order.paid', ['order' => $order]);          // объявить событие
$driver = Events::filter('mail.driver', $driver);          // пропустить значение по цепочке
```

Разница простая: `fire()` уведомляет, `filter()` даёт подписчику подменить значение.
Упавший слушатель не роняет остальных — ошибка уходит в лог.

События ядра:

| Событие | Когда | Что в payload |
|---------|-------|---------------|
| `user.registered` | завели пользователя (регистрация или админ) | `user` |
| `user.login` | вошёл | `user` |
| `user.logout` | вышел | `user` |
| `mail.sending` | письмо уходит; `false` от слушателя отменяет отправку | `message` |
| `mail.sent` | письмо ушло | `message` |
| `mail.failed` | письмо не ушло | `message`, `error` |
| `mail.driver` | фильтр: чем отправлять | значение — драйвер |
| `notification.sent` | уведомление доставлено хотя бы одним каналом | `user`, `notification`, `channels` |

События из реестра `config/webhooks.php` заодно уезжают подписчикам вебхуков —
подробности в [WEBHOOKS.md](WEBHOOKS.md).

## Кэш

Файловый кэш в `var/cache`, ключ — строка, срок — секунды.

```php
$stats = Cache::remember('dashboard:stats', 300, static fn () => Report::heavy());

Cache::put('key', $value, 60);
Cache::get('key');        // null, если нет или протух
Cache::has('key');
Cache::forget('key');
Cache::flush();           // всё разом; то же делает php bin/proton cache:clear
```

`remember()` считает значение один раз и держит его до срока. Нулевой срок означает
«не кэшировать» — удобно выключать кэш настройкой, не трогая код. Просроченные файлы
убирает воркер, отдельной уборки не нужно.
