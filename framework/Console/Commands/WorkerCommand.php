<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Console\Commands;

use Rsgrinko\Proton\Console\Command;
use Rsgrinko\Proton\Queue\Queue;
use Rsgrinko\Proton\Queue\Worker;
use Rsgrinko\Proton\Webhooks\Webhooks;

/**
 * воркер очереди.
 */
final class WorkerCommand extends Command
{
    public function name(): string
    {
        return 'worker';
    }

    public function description(): string
    {
        return 'разбирать очередь задач и вести расписание';
    }

    public function usage(): string
    {
        return 'worker [--once] [--queue=default,webhooks]';
    }

    public function run(): int
    {
        // Очередей можно перечислить несколько через запятую: задача из очереди
        // слева берётся раньше
        $queue  = (string) $this->option('queue', Queue::DEFAULT_QUEUE . ',' . Webhooks::queue());
        $result = (new Worker($queue))->run($this->hasOption('once'));

        $this->line('Выполнено: ' . $result['done'] . ', с ошибкой: ' . $result['failed']);

        return 0;
    }
}
