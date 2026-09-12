<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Mail\Drivers;

use Rsgrinko\Proton\Mail\Message;
use Rsgrinko\Proton\Mail\Mime;
use Rsgrinko\Proton\Support\Config;

/**
 * Письмо не уходит никуда, а сохраняется файлом .eml в var/log/mail.
 *
 * Режим для разработки: письмо со ссылкой подтверждения можно открыть в любом
 * почтовом клиенте и нажать кнопку, ничего никому не отправив.
 */
final class LogDriver implements DriverInterface
{
    public function send(Message $message): void
    {
        $dir = (string) Config::get('paths.log', APP_ROOT . '/var/log') . '/mail';

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $name = date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.eml';

        @file_put_contents($dir . '/' . $name, Mime::build($message));
    }

    public function name(): string
    {
        return 'log';
    }
}
