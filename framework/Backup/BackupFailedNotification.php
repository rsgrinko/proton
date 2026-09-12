<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Backup;

use Rsgrinko\Proton\Http\Router;
use Rsgrinko\Proton\Notifications\Notification;
use Rsgrinko\Proton\Notifications\Notify;

/**
 * Копия базы не сделалась.
 *
 * Уходит тем, кто отвечает за обслуживание, и письмом тоже: о том, что копий
 * больше нет, узнавать хочется до того, как они понадобятся.
 */
final class BackupFailedNotification extends Notification
{
    public function __construct(private string $error)
    {
    }

    public function type(): string
    {
        return 'backup.failed';
    }

    public function title(): string
    {
        return 'Резервная копия базы не сделалась';
    }

    public function body(): string
    {
        return 'Ошибка: ' . $this->error . '. Пока это не поправлено, свежих копий нет — '
            . 'проверьте место на диске и права на каталог копий.';
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
