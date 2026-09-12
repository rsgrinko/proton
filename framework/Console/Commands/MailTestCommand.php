<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Console\Commands;

use Rsgrinko\Proton\Console\Command;
use Rsgrinko\Proton\Mail\Mail;
use Rsgrinko\Proton\Mail\Message;
use Rsgrinko\Proton\Support\Config;
use Rsgrinko\Proton\Support\Validator;
use Throwable;

/**
 * проверить отправку почты.
 */
final class MailTestCommand extends Command
{
    public function name(): string
    {
        return 'mail:test';
    }

    public function description(): string
    {
        return 'отправить пробное письмо текущим драйвером';
    }

    public function usage(): string
    {
        return 'mail:test <адрес> [--queue]';
    }

    public function run(): int
    {
        $to = trim($this->arg(0));

        if (!Validator::isEmail($to)) {
            $this->fail('Укажите адрес: php bin/proton mail:test user@example.com');

            return 1;
        }

        $driver = Mail::driver()->name();

        $message = Message::to($to)
            ->subject('Пробное письмо ' . (string) Config::get('app.name', 'Proton'))
            ->text(
                "Это пробное письмо.\n\n"
                . 'Драйвер: ' . $driver . "\n"
                . 'Отправитель: ' . (string) Config::get('mail.from_email', '') . "\n"
                . 'Время: ' . date('d.m.Y H:i:s') . "\n"
            );

        try {
            if ($this->hasOption('queue')) {
                $id = $message->queue();

                $this->ok('Письмо поставлено в очередь, задача ' . $id . ' — его отправит воркер');

                return 0;
            }

            $message->send();

            $this->ok('Письмо отправлено драйвером ' . $driver);

            if ($driver === 'log') {
                $this->line('  Файл лежит в ' . (string) Config::get('paths.log') . '/mail');
            }

            return 0;
        } catch (Throwable $e) {
            $this->fail($e->getMessage());

            return 1;
        }
    }
}
