<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Backup;

use Rsgrinko\Proton\Http\Router;
use Rsgrinko\Proton\Notifications\Notification;
use Rsgrinko\Proton\Notifications\Notify;

/**
 * Копия сделана и годна, но на FTP не уехала.
 *
 * Отдельно от `BackupFailedNotification`: там нет вообще никакой свежей копии,
 * здесь она есть и лежит локально — беда меньше, но диск с копиями остаётся
 * тем же, что и диск с базой, а это как раз то, чего FTP должен был избежать.
 */
final class BackupShipFailedNotification extends Notification
{
    public function __construct(private string $name, private string $error)
    {
    }

    public function type(): string
    {
        return 'backup.ship_failed';
    }

    public function title(): string
    {
        return 'Копия базы не уехала на FTP';
    }

    public function body(): string
    {
        return 'Копия «' . $this->name . '» сделана и годна, но осталась только локально. '
            . 'Ошибка: ' . $this->error;
    }

    public function url(): string
    {
        return Router::absolute('admin.backups');
    }

    /**
     * @return array<int, string>
     */
    public function channels(): array
    {
        return [Notify::DATABASE, Notify::MAIL];
    }
}
