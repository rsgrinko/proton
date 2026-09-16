<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Backup;

use Rsgrinko\Proton\Access\Permission;
use Rsgrinko\Proton\Notifications\Notify;
use Rsgrinko\Proton\Queue\Job;
use Rsgrinko\Proton\Support\Logger;
use Rsgrinko\Proton\Support\ProtonException;

/**
 * Отправляет готовую копию базы на FTP. Отдельная задача от `BackupJob` —
 * дамп и так может занять минуты, а сеть до чужого FTP не должна держать
 * их дольше: копия остаётся локально годной, даже если отправка не задалась.
 */
final class ShipBackupJob extends Job
{
    public function attempts(): int
    {
        return 3;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload): void
    {
        $name = (string) ($payload['name'] ?? '');

        if ($name === '') {
            throw new ProtonException('В задаче нет имени копии');
        }

        Ftp::upload(Backup::resolve($name));

        (new Logger('backup'))->info('Копия отправлена на FTP', ['name' => $name]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function failed(array $payload, string $error): void
    {
        $name = (string) ($payload['name'] ?? '');

        Notify::toPermission(Permission::SYSTEM_MANAGE, new BackupShipFailedNotification($name, $error));
    }
}
