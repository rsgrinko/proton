<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Console\Commands;

use Rsgrinko\Proton\Console\Command;
use Rsgrinko\Proton\Support\Maintenance;

/**
 * выключить режим обслуживания.
 */
final class UpCommand extends Command
{
    public function name(): string
    {
        return 'up';
    }

    public function description(): string
    {
        return 'выключить режим обслуживания';
    }

    public function run(): int
    {
        if (!Maintenance::active()) {
            $this->line('Режим обслуживания и так выключен');

            return 0;
        }

        Maintenance::deactivate();

        $this->ok('Режим обслуживания выключен');

        return 0;
    }
}
