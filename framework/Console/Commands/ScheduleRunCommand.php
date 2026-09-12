<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Console\Commands;

use Rsgrinko\Proton\Console\Command;
use Rsgrinko\Proton\Queue\Scheduler;

/**
 * прогнать расписание.
 *
 * Нужна там, где вместо воркера-демона используется cron: строка
 * `* * * * * php bin/proton schedule:run` делает то же, что воркер между задачами.
 */
final class ScheduleRunCommand extends Command
{
    public function name(): string
    {
        return 'schedule:run';
    }

    public function description(): string
    {
        return 'выполнить задачи расписания, которым пора';
    }

    public function usage(): string
    {
        return 'schedule:run [--list]';
    }

    public function run(): int
    {
        if ($this->hasOption('list')) {
            $rows = [];

            foreach (Scheduler::tasks() as $task) {
                $rows[] = [
                    $task['name'],
                    $task['at'] !== '' ? 'ежедневно в ' . $task['at'] : 'раз в ' . $task['interval'] . ' с',
                    $task['last'] !== '' ? $task['last'] : 'ни разу',
                ];
            }

            if ($rows === []) {
                $this->line('Расписание пусто — задачи объявляются в config/schedule.php');

                return 0;
            }

            $this->table(['Задача', 'Когда', 'Последний запуск'], $rows);

            return 0;
        }

        $done = Scheduler::run();

        if ($done === []) {
            $this->line('Пока нечего выполнять');

            return 0;
        }

        foreach ($done as $name) {
            $this->ok('выполнена ' . $name);
        }

        return 0;
    }
}
