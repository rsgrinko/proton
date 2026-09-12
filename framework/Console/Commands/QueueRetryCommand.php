<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Console\Commands;

use Rsgrinko\Proton\Console\Command;
use Rsgrinko\Proton\Database\Connection;
use Rsgrinko\Proton\Queue\Queue;

/**
 * повторить задачи.
 */
final class QueueRetryCommand extends Command
{
    public function name(): string
    {
        return 'queue:retry';
    }

    public function description(): string
    {
        return 'вернуть в очередь неудавшуюся задачу или все сразу';
    }

    public function usage(): string
    {
        return 'queue:retry <id|--failed>';
    }

    public function run(): int
    {
        if ($this->hasOption('failed')) {
            $ids = Connection::instance()->select(
                'SELECT id FROM jobs WHERE status = :status',
                ['status' => Queue::FAILED]
            );

            foreach ($ids as $row) {
                Queue::retry((int) $row['id']);
            }

            $this->ok('Возвращено в очередь: ' . count($ids));

            return 0;
        }

        $id = (int) $this->arg(0);

        if ($id === 0) {
            $this->fail('Укажите номер задачи или --failed');

            return 1;
        }

        if (!Queue::retry($id)) {
            $this->fail('Задача ' . $id . ' не найдена');

            return 1;
        }

        $this->ok('Задача ' . $id . ' возвращена в очередь');

        return 0;
    }
}
