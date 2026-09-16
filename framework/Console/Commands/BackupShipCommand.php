<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Console\Commands;

use Rsgrinko\Proton\Backup\Backup;
use Rsgrinko\Proton\Backup\Ftp;
use Rsgrinko\Proton\Backup\ShipBackupJob;
use Rsgrinko\Proton\Console\Command;
use Rsgrinko\Proton\Queue\Queue;
use Throwable;

/**
 * отправить копию базы на FTP.
 */
final class BackupShipCommand extends Command
{
    public function name(): string
    {
        return 'backup:ship';
    }

    public function description(): string
    {
        return 'отправить готовую копию базы на FTP';
    }

    public function usage(): string
    {
        return 'backup:ship <файл> [--queue]';
    }

    public function run(): int
    {
        if (!Ftp::enabled()) {
            $this->fail('FTP не настроен: задайте FTP_HOST в .env');

            return 1;
        }

        $name = trim($this->arg(0));

        if ($name === '') {
            $this->fail('Укажите файл копии: backup:ship <файл> (список — backup:list)');

            return 1;
        }

        $check = Backup::verify($name);

        if (!$check['ok']) {
            $this->fail('Копия негодна: ' . $check['message']);

            return 1;
        }

        if ($this->hasOption('queue')) {
            $id = Queue::push(ShipBackupJob::class, ['name' => $name]);

            $this->ok('Отправка поставлена в очередь, задача ' . $id . ' — её заберёт воркер');

            return 0;
        }

        try {
            Ftp::upload(Backup::resolve($name));
        } catch (Throwable $e) {
            $this->fail('Не отправилась: ' . $e->getMessage());

            return 1;
        }

        $this->ok('Копия «' . $name . '» отправлена на FTP');

        return 0;
    }
}
