<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Console\Commands;

use Rsgrinko\Proton\Console\Command;
use Rsgrinko\Proton\Database\Migrator;

/**
 * применить миграции.
 */
final class MigrateCommand extends Command
{
    public function name(): string
    {
        return 'migrate';
    }

    public function description(): string
    {
        return 'применить новые миграции';
    }

    public function usage(): string
    {
        return 'migrate [--pretend]';
    }

    public function run(): int
    {
        $migrator = new Migrator();

        if ($this->hasOption('pretend')) {
            $plan = $migrator->pretend();

            if ($plan === []) {
                $this->line('Новых миграций нет');

                return 0;
            }

            foreach ($plan as $name => $queries) {
                $this->line($name . ':');

                foreach ($queries as $query) {
                    $this->line('  ' . $query);
                }
            }

            return 0;
        }

        $applied = $migrator->run();

        if ($applied === []) {
            $this->line('Новых миграций нет');

            return 0;
        }

        foreach ($applied as $name) {
            $this->ok('применена ' . $name);
        }

        // Шаги, которые уже были в базе: так выглядит докат миграции,
        // упавшей на середине, — молчать об этом нельзя
        $skipped = $migrator->skipped();

        if ($skipped !== []) {
            $this->line('');
            $this->line('Пропущено как уже выполненное:');

            foreach ($skipped as $query) {
                $this->line('  ' . $query);
            }
        }

        return 0;
    }
}
