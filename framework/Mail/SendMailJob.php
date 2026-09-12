<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Mail;

use Rsgrinko\Proton\Queue\Job;

/**
 * Отправка письма из очереди: сюда попадает всё, что ушло через Mail::queue().
 *
 * Ради этого письма и стоит очередь: SMTP чужого провайдера отвечает секундами,
 * а страница регистрации не должна их ждать.
 */
final class SendMailJob extends Job
{
    public function attempts(): int
    {
        return 5;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload): void
    {
        Mail::send(Message::fromArray($payload));
    }
}
