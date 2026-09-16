<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Support;

use Rsgrinko\Proton\Models\User;
use Rsgrinko\Proton\Notifications\ExportReadyNotification;
use Rsgrinko\Proton\Notifications\Notify;
use Rsgrinko\Proton\Queue\Job;

/**
 * Второй шаг цепочки после ExportJob: файл уже готов и лежит на диске, дело
 * только за письмом. Отдельная задача — чтобы сорвавшееся письмо не заставляло
 * пересобирать сам файл повторной попыткой всей выгрузки.
 */
final class NotifyExportReadyJob extends Job
{
    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload): void
    {
        $userId = (int) ($payload['user_id'] ?? 0);
        $name   = (string) ($payload['name'] ?? '');

        $user = User::find($userId);

        if ($user === null || $name === '') {
            (new Logger('export'))->warning('Уведомлять о выгрузке некого', ['user' => $userId, 'name' => $name]);

            return;
        }

        Notify::send($user, new ExportReadyNotification(
            (string) ($payload['label'] ?? ''),
            $name,
            (int) ($payload['rows'] ?? 0)
        ));
    }
}
