<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Mail\Drivers;

use Rsgrinko\Proton\Mail\Message;

/**
 * Заглушка: письмо никуда не идёт и нигде не сохраняется.
 * Нужна тестам — проверить, что письмо собралось, можно через Mail::sent().
 */
final class NullDriver implements DriverInterface
{
    public function send(Message $message): void
    {
        // Ничего не делаем: письмо считается отправленным
    }

    public function name(): string
    {
        return 'null';
    }
}
