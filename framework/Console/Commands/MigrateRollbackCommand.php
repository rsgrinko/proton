<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Console\Commands;

use Rsgrinko\Proton\Console\Command;
use Rsgrinko\Proton\Database\Migrator;

/**
 * откатить последнюю пачку миграций.
 */
final class MigrateRollbackCommand extends Command
{
    public function name(): string
    {
        return 'migrate:rollback';
    }

    public function description(): string
    {
        return 'откатить последнюю пачку миграций';
    }

    public function usage(): string
    {
        return 'migrate:rollback [--steps=1] [--force]';
    }

    public function run(): int
    {
        // Откат удаляет колонки вместе с данными — на живой базе это крайняя мера
        if (!$this->confirm('Откат удалит колонки вместе с данными. Продолжать?')) {
            $this->line('Отменено');

            return 1;
        }

        $steps = max(1, (int) $this->option('steps', '1'));

        $rolledBack = (new Migrator())->rollback($steps);

        if ($rolledBack === []) {
            $this->line('Откатывать нечего');

            return 0;
        }

        foreach ($rolledBack as $name) {
            $this->ok('откачена ' . $name);
        }

        return 0;
    }
}
