<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Console\Commands;

use Rsgrinko\Proton\Console\Command;
use Rsgrinko\Proton\Queue\Queue;

/**
 * подчистить очередь.
 */
final class QueuePurgeCommand extends Command
{
    public function name(): string
    {
        return 'queue:purge';
    }

    public function description(): string
    {
        return 'удалить выполненные задачи старше срока';
    }

    public function usage(): string
    {
        return 'queue:purge [--days=7]';
    }

    public function run(): int
    {
        $days = max(0, (int) $this->option('days', '7'));

        $removed = Queue::purge($days);
        $stuck   = Queue::releaseStuck();

        $this->ok('Удалено выполненных: ' . $removed);

        if ($stuck > 0) {
            $this->ok('Возвращено зависших: ' . $stuck);
        }

        return 0;
    }
}
