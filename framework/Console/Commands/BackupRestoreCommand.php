<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Console\Commands;

use Rsgrinko\Proton\Backup\Backup;
use Rsgrinko\Proton\Console\Command;

/**
 * восстановление базы из копии.
 */
final class BackupRestoreCommand extends Command
{
    public function name(): string
    {
        return 'backup:restore';
    }

    public function description(): string
    {
        return 'восстановить базу из копии (текущие данные будут заменены)';
    }

    public function usage(): string
    {
        return 'backup:restore <файл> --force';
    }

    public function run(): int
    {
        $name = $this->arg(0);

        if ($name === '') {
            $this->fail('Укажите файл копии: backup:restore <файл> --force (список — backup:list)');

            return 1;
        }

        $check = Backup::verify($name);

        if (!$check['ok']) {
            $this->fail('Копия негодна: ' . $check['message']);

            return 1;
        }

        $this->line('Копия:  ' . $name);
        $this->line('Таблиц: ' . $check['tables']);
        $this->line();

        // Восстановление заменяет данные целиком, поэтому ключ --force обязателен:
        // случайно набранная команда ничего не затрёт
        if (!$this->hasOption('force')) {
            $this->fail('Это заменит все текущие данные. Если решение принято, повторите с ключом --force');

            return 1;
        }

        $result = Backup::restore($name);

        $this->line('Перед заменой сделана копия того, что было: ' . $result['backup']);
        $this->ok('База восстановлена, таблиц: ' . $result['tables']);
        $this->line('Воркер нужно перезапустить: php bin/proton worker:restart');

        return 0;
    }
}
