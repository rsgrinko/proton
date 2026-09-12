<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Console\Commands;

use Rsgrinko\Proton\Backup\Backup;
use Rsgrinko\Proton\Console\Command;

/**
 * копия базы.
 */
final class BackupCreateCommand extends Command
{
    public function name(): string
    {
        return 'backup:create';
    }

    public function description(): string
    {
        return 'сделать копию базы и проверить её';
    }

    public function usage(): string
    {
        return 'backup:create [--keep=7]';
    }

    public function run(): int
    {
        $info = Backup::create();

        $this->line('Файл:    ' . $info['name']);
        $this->line('Размер:  ' . Backup::size($info['size']));
        $this->line('Таблиц:  ' . $info['tables']);
        $this->line('Время:   ' . $info['ms'] . ' мс');

        $keep    = $this->option('keep');
        $removed = Backup::rotate($keep === null ? null : (int) $keep);

        if ($removed > 0) {
            $this->line('Старых копий удалено: ' . $removed);
        }

        $this->ok('Копия готова и проверена');

        return 0;
    }
}
