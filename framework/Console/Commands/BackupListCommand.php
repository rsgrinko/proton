<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Console\Commands;

use Rsgrinko\Proton\Backup\Backup;
use Rsgrinko\Proton\Console\Command;
use Rsgrinko\Proton\Support\Config;

/**
 * список копий базы.
 */
final class BackupListCommand extends Command
{
    public function name(): string
    {
        return 'backup:list';
    }

    public function description(): string
    {
        return 'список копий базы; --check проверяет каждую';
    }

    public function usage(): string
    {
        return 'backup:list [--check]';
    }

    public function run(): int
    {
        $files = Backup::files();

        if ($files === []) {
            $this->line('Копий нет. Сделать: php bin/proton backup:create');

            return 0;
        }

        $check = $this->hasOption('check');
        $rows  = [];

        foreach ($files as $file) {
            $row = [$file['name'], Backup::size($file['size']), $file['created']];

            if ($check) {
                $result = Backup::verify($file['name']);
                $row[]  = ($result['ok'] ? 'годна' : 'НЕГОДНА') . ' — ' . $result['message'];
            }

            $rows[] = $row;
        }

        $headers = ['Файл', 'Размер', 'Когда'];

        if ($check) {
            $headers[] = 'Проверка';
        }

        $this->table($headers, $rows);

        $this->line();
        $this->line('Каталог: ' . Backup::directory());
        $this->line('Держим копий: ' . (int) Config::get('backup.keep', 7));

        $at = trim((string) Config::get('backup.schedule', ''));

        $this->line('По расписанию: ' . ($at !== '' ? 'каждый день в ' . $at : 'выключено (BACKUP_SCHEDULE)'));

        return 0;
    }
}
