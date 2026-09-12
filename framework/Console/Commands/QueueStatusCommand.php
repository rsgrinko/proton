<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Console\Commands;

use Rsgrinko\Proton\Console\Command;
use Rsgrinko\Proton\Database\Connection;
use Rsgrinko\Proton\Queue\Queue;

/**
 * состояние очереди.
 */
final class QueueStatusCommand extends Command
{
    public function name(): string
    {
        return 'queue:status';
    }

    public function description(): string
    {
        return 'сколько задач в очереди и что упало';
    }

    public function usage(): string
    {
        return 'queue:status [--failed]';
    }

    public function run(): int
    {
        $stats = Queue::stats();

        if ($stats === []) {
            $this->line('Таблицы очереди ещё нет — накатите миграции');

            return 0;
        }

        $labels = [
            Queue::QUEUED  => 'в очереди',
            Queue::RUNNING => 'выполняется',
            Queue::DONE    => 'выполнено',
            Queue::FAILED  => 'с ошибкой',
        ];

        $rows = [];

        foreach ($labels as $status => $label) {
            $rows[] = [$label, (string) ($stats[$status] ?? 0)];
        }

        $this->table(['Состояние', 'Задач'], $rows);

        if (!$this->hasOption('failed')) {
            return 0;
        }

        $failed = Connection::instance()->select(
            'SELECT id, job_class, attempts, error FROM jobs WHERE status = :status ORDER BY id DESC LIMIT 20',
            ['status' => Queue::FAILED]
        );

        if ($failed === []) {
            $this->line('');
            $this->line('Неудавшихся задач нет');

            return 0;
        }

        $this->line('');
        $this->table(
            ['id', 'Задача', 'Попыток', 'Ошибка'],
            array_map(
                static fn (array $row): array => [
                    (string) $row['id'],
                    (string) $row['job_class'],
                    (string) $row['attempts'],
                    mb_substr((string) $row['error'], 0, 60),
                ],
                $failed
            )
        );

        return 0;
    }
}
