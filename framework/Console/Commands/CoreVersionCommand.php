<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Console\Commands;

use Rsgrinko\Proton\Console\Command;
use Rsgrinko\Proton\Core\Updater;

/**
 * версия ядра, на которой сидит проект.
 */
final class CoreVersionCommand extends Command
{
    public function name(): string
    {
        return 'core:version';
    }

    public function description(): string
    {
        return 'версия ядра, на которой сидит проект (framework/VERSION)';
    }

    public function run(): int
    {
        $this->line((new Updater(APP_ROOT))->localVersion());

        return 0;
    }
}
