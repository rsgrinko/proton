# Письма

Одно и то же письмо собирается одинаково, а уходит тем способом, который выбран
настройкой. Места отправки про способ не знают — это даёт переехать с `mail()` на
SMTP или на внешний сервис, не трогая код приложения.

## Как отправить

```php
use Rsgrinko\Proton\Mail\Message;

Message::to($user->email)
    ->subject('Отчёт за сентябрь')
    ->view('mail/report', ['user' => $user, 'total' => $total])
    ->queue();          // в очередь (обычный путь)
```

`send()` вместо `queue()` отправляет прямо сейчас — это нужно там, где ответ зависит
от результата. Всё остальное лучше ставить в очередь: почтовый сервер отвечает
секундами, а пользователь ждёт страницу.

Полный набор:

```php
Message::to('one@example.com', 'two@example.com')
    ->addTo('three@example.com')
    ->copy('boss@example.com')            // Cc
    ->blindCopy('archive@example.com')    // Bcc
    ->from('robot@example.com', 'Робот')  // по умолчанию MAIL_FROM
    ->replyTo('support@example.com')
    ->subject('Тема')
    ->text('Простой текст')               // текстовая часть
    ->html('<p>Разметка</p>')             // и/или HTML
    ->view('mail/report', [...])          // шаблон вместо html(): resources/views/mail/report.php
    ->header('X-Campaign', 'september')
    ->attach('счёт.pdf', $bytes, 'application/pdf')
    ->attachFile('/path/to/файл.xlsx')
    ->queue(60);                          // через минуту
```

Текстовую часть стоит давать всегда: письмо без неё чаще уезжает в спам, а в
почтовых клиентах без HTML выглядит пустым.

## Драйверы

Способ отправки задаёт `MAIL_DRIVER`:

| Драйвер | Чем шлёт | Когда нужен |
|---------|----------|-------------|
| `mail` | функция `mail()` | работает везде, ничего настраивать не нужно |
| `smtp` | свой SMTP-клиент | обычный боевой вариант |
| `mailer` | внешний почтовый сервис по HTTP API | очередь, повторы, стоп-лист и DKIM — на его стороне |
| `log` | складывает письма файлами в `var/log/mail` | разработка: письмо можно открыть и посмотреть |
| `null` | выбрасывает | тесты |

SMTP настраивается переменными `SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASSWORD`,
`SMTP_ENCRYPTION` (пусто, `ssl` для 465, `tls` для 587), `SMTP_TIMEOUT`,
`SMTP_VERIFY_PEER`, `SMTP_HELO`, `SMTP_SESSION_LIMIT`.

Внешнему сервису нужны `MAIL_SERVICE_URL` и `MAIL_SERVICE_KEY` (ключ проекта):
письмо уходит одним `POST /api/v1/messages`, а очередь, повторы, стоп-лист, подпись
и отчёты о доставке остаются на его стороне. `curl` не обязателен — без расширения
запрос уходит потоками.

Проверить, что настроено, можно не отправкой руками:

```bash
php bin/proton mail:test you@example.com            # прямо сейчас
php bin/proton mail:test you@example.com --queue    # через очередь, как в бою
php bin/proton status                               # какой драйвер и от кого шлём
```

## Подмена способа отправки

Свой драйвер — это класс с одним методом:

```php
final class MyDriver implements Rsgrinko\Proton\Mail\Drivers\DriverInterface
{
    public function send(Message $message): void
    {
        // отправить или бросить исключение
    }
}
```

Подставляется он событием, без правки мест отправки (`config/events.php`):

```php
Events::listen('mail.driver', static fn () => new App\Mail\MyDriver());
```

Рядом живут события всего пути письма: `mail.sending` (вернуть `false` — письмо не
уйдёт), `mail.sent`, `mail.failed`. Через них удобно вести свой журнал рассылок или
глушить письма на стенде.

## Письма в очереди

`queue()` кладёт письмо в обычную очередь задачей `SendMailJob` — со своими попытками
и паузами между ними. Значит, письма разбирает воркер: без запущенного
`php bin/proton worker` они будут копиться в `queue:status`, а не уходить.

Упавшие письма видно там же, где остальные задачи:

```bash
php bin/proton queue:status --failed
php bin/proton queue:retry --failed
```

## В тестах

Отправка наружу в тестах не нужна: драйвер `null` выбрасывает письма, а `Mail::sent()`
показывает, что ушло бы.

```php
Mail::forget();                       // забыть прошлые письма
Message::to('a@example.com')->subject('Тема')->send();

assertCount(1, Mail::sent());
assertSame('Тема', Mail::sent()[0]->subjectLine());
```

Свой драйвер на время теста подставляется через `Mail::setDriver()`; `setDriver(null)`
возвращает выбранный настройкой.
