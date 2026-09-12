<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Mail\Drivers;

use Rsgrinko\Proton\Mail\Message;
use Rsgrinko\Proton\Mail\Mime;
use Rsgrinko\Proton\Support\ProtonException;

/**
 * Отправка через встроенную функцию mail() — то есть через sendmail хостинга.
 *
 * Драйвер по умолчанию: работает везде и не требует настроек. Очереди и повторов
 * у него нет, ошибку он показывает одним булевым значением — для рабочего проекта
 * лучше SMTP или почтовый сервис, но для старта этого достаточно.
 */
final class MailDriver implements DriverInterface
{
    public function send(Message $message): void
    {
        $body    = Mime::body($message);
        $headers = array_merge(Mime::headers($message, false), $body['headers']);

        // Получателей mail() принимает отдельным аргументом, в заголовках их быть
        // не должно — иначе адресат увидит себя дважды
        if ($message->copies() !== []) {
            $headers['Cc'] = implode(', ', $message->copies());
        }

        if ($message->blindCopies() !== []) {
            $headers['Bcc'] = implode(', ', $message->blindCopies());
        }

        $lines = [];

        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        // Пятый аргумент задаёт обратный адрес конверта: без него письма уходят
        // от пользователя веб-сервера и часто попадают в спам
        $sent = @mail(
            implode(', ', $message->recipients()),
            Mime::encodeHeader($message->subjectLine()),
            $body['body'],
            implode("\r\n", $lines),
            '-f' . $message->senderEmail()
        );

        if (!$sent) {
            throw new ProtonException('Функция mail() не приняла письмо. Проверьте sendmail_path в php.ini');
        }
    }

    public function name(): string
    {
        return 'mail';
    }
}
