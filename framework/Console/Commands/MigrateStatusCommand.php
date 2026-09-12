<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Console\Commands;

use Rsgrinko\Proton\Console\Command;
use Rsgrinko\Proton\Database\Migrator;

/**
 * что применено, что ждёт.
 */
final class MigrateStatusCommand extends Command
{
    public function name(): string
    {
        return 'migrate:status';
    }

    public function description(): string
    {
        return 'показать состояние миграций';
    }

    public function run(): int
    {
        $migrator = new Migrator();

        $applied = $migrator->applied();
        $pending = $migrator->pending();
        $unknown = $migrator->unknown();

        $rows = [];

        foreach (array_keys($migrator->migrations()) as $name) {
            $rows[] = [$name, in_array($name, $applied, true) ? 'применена' : 'ждёт'];
        }

        if ($rows === []) {
            $this->line('Миграций нет');

            return 0;
        }

        $this->table(['Миграция', 'Состояние'], $rows);

        if ($unknown !== []) {
            $this->line('');
            $this->line('Есть в базе, но нет в коде (откат релиза?):');

            foreach ($unknown as $name) {
                $this->line('  ' . $name);
            }
        }

        $this->line('');
        $this->line('Применено: ' . count($applied) . ', ждёт: ' . count($pending));

        return 0;
    }
}
