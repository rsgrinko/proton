<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Mail\Drivers;

use Rsgrinko\Proton\Mail\Message;

/**
 * Способ доставки письма. Новый драйвер — класс с этими двумя методами
 * и строка в реестре Mail::DRIVERS (либо подмена через событие mail.driver).
 */
interface DriverInterface
{
    /**
     * Отправляет письмо. Не смог — бросает исключение: молча терять письмо нельзя.
     */
    public function send(Message $message): void;

    /**
     * Короткое имя для логов и страницы состояния.
     */
    public function name(): string;
}
