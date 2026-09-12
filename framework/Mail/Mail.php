<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Mail;

use Rsgrinko\Proton\Events\Events;
use Rsgrinko\Proton\Mail\Drivers\DriverInterface;
use Rsgrinko\Proton\Mail\Drivers\LogDriver;
use Rsgrinko\Proton\Mail\Drivers\MailDriver;
use Rsgrinko\Proton\Mail\Drivers\MailerServiceDriver;
use Rsgrinko\Proton\Mail\Drivers\NullDriver;
use Rsgrinko\Proton\Mail\Drivers\SmtpDriver;
use Rsgrinko\Proton\Queue\Queue;
use Rsgrinko\Proton\Support\Config;
use Rsgrinko\Proton\Support\Logger;
use Rsgrinko\Proton\Support\ProtonException;
use Throwable;

/**
 * Отправка писем. Драйвер выбирается настройкой mail.driver:
 *
 *   mail   — функция mail() (по умолчанию: работает везде, ничего не настраивая);
 *   smtp   — свой SMTP-клиент;
 *   mailer — почтовый сервис по HTTP API (очередь, ретраи и отчёты на его стороне);
 *   log    — письмо пишется в var/log и никуда не уходит (разработка);
 *   null   — письмо просто выбрасывается (тесты).
 *
 * Драйвер можно подменить и не трогая конфиг: событие-фильтр mail.driver
 * получает готовый объект и может вернуть свой. Так проект переезжает на чужую
 * рассылку, не переписывая ни строчки в местах отправки.
 */
final class Mail
{
    /** @var array<string, class-string<DriverInterface>> */
    private const DRIVERS = [
        'mail'   => MailDriver::class,
        'smtp'   => SmtpDriver::class,
        'mailer' => MailerServiceDriver::class,
        'log'    => LogDriver::class,
        'null'   => NullDriver::class,
    ];

    /** Подменённый драйвер — тесты и ручная настройка */
    private static ?DriverInterface $driver = null;

    /** @var array<int, Message> Письма, отправленные при подменённом драйвере-заглушке */
    private static array $sent = [];

    /**
     * Отправляет письмо прямо сейчас.
     */
    public static function send(Message $message): bool
    {
        if ($message->recipients() === []) {
            throw new ProtonException('У письма нет получателей');
        }

        if ($message->senderEmail() === '') {
            throw new ProtonException('Не задан отправитель: заполните MAIL_FROM в .env');
        }

        // Событие «письмо уходит»: слушатель может дописать заголовки или
        // отменить отправку, вернув false
        $allowed = Events::fire('mail.sending', ['message' => $message]);

        if (in_array(false, $allowed, true)) {
            return false;
        }

        $driver = self::driver();

        try {
            $driver->send($message);
        } catch (Throwable $e) {
            (new Logger('mail'))->error('Письмо не отправлено', [
                'to'     => implode(', ', $message->recipients()),
                'driver' => $driver->name(),
                'error'  => $e->getMessage(),
            ]);

            Events::fire('mail.failed', ['message' => $message, 'error' => $e->getMessage()]);

            throw $e;
        }

        self::$sent[] = $message;

        (new Logger('mail'))->info('Письмо отправлено', [
            'to'      => implode(', ', $message->recipients()),
            'subject' => $message->subjectLine(),
            'driver'  => $driver->name(),
        ]);

        Events::fire('mail.sent', ['message' => $message]);

        return true;
    }

    /**
     * Кладёт письмо в очередь: страница не ждёт SMTP, отправкой займётся воркер.
     */
    public static function queue(Message $message, int $delaySeconds = 0): int
    {
        return Queue::push(SendMailJob::class, $message->toArray(), $delaySeconds);
    }

    /**
     * Текущий драйвер.
     */
    public static function driver(): DriverInterface
    {
        if (self::$driver !== null) {
            return self::$driver;
        }

        $name  = (string) Config::get('mail.driver', 'mail');
        $class = self::DRIVERS[$name] ?? null;

        if ($class === null) {
            throw new ProtonException('Неизвестный драйвер почты: ' . $name);
        }

        /** @var DriverInterface $driver */
        $driver = new $class();

        // Последнее слово — за приложением: фильтр может вернуть свой драйвер
        $filtered = Events::filter('mail.driver', $driver);

        return $filtered instanceof DriverInterface ? $filtered : $driver;
    }

    /**
     * Подменить драйвер — тесты и разовые задачи.
     */
    public static function setDriver(?DriverInterface $driver): void
    {
        self::$driver = $driver;
    }

    /**
     * Письма, отправленные за этот процесс — нужно тестам.
     *
     * @return array<int, Message>
     */
    public static function sent(): array
    {
        return self::$sent;
    }

    public static function forget(): void
    {
        self::$sent = [];
    }

    /**
     * Список доступных драйверов — показывает состояние и справка.
     *
     * @return array<int, string>
     */
    public static function drivers(): array
    {
        return array_keys(self::DRIVERS);
    }
}
