<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Console\Commands;

use Rsgrinko\Proton\Console\Command;
use Rsgrinko\Proton\Support\Maintenance;

/**
 * включить режим обслуживания.
 */
final class DownCommand extends Command
{
    public function name(): string
    {
        return 'down';
    }

    public function description(): string
    {
        return 'включить режим обслуживания (503 всем, кроме system.manage и --allow)';
    }

    public function usage(): string
    {
        return 'down [--message="Текст"] [--allow=IP,IP] [--retry=60]';
    }

    public function run(): int
    {
        Maintenance::activate(
            (string) $this->option('message', ''),
            (string) $this->option('allow', ''),
            (int) $this->option('retry', '0')
        );

        $this->ok('Режим обслуживания включён — свои (system.manage) работают как обычно');

        return 0;
    }
}
