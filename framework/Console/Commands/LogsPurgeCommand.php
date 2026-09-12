<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Console\Commands;

use Rsgrinko\Proton\Console\Command;
use Rsgrinko\Proton\Support\Logger;

/**
 * удалить старые логи.
 */
final class LogsPurgeCommand extends Command
{
    public function name(): string
    {
        return 'logs:purge';
    }

    public function description(): string
    {
        return 'удалить логи старше указанного срока';
    }

    public function usage(): string
    {
        return 'logs:purge [--days=30]';
    }

    public function run(): int
    {
        $days = $this->hasOption('days') ? (int) $this->option('days') : null;

        $removed = (new Logger())->purge($days);

        if ($removed === []) {
            $this->line('Удалять нечего');

            return 0;
        }

        foreach ($removed as $file) {
            $this->ok('удалён ' . $file);
        }

        return 0;
    }
}
