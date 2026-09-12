<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Queue;

use Rsgrinko\Proton\Backup\BackupJob;
use Rsgrinko\Proton\Models\Setting;
use Rsgrinko\Proton\Support\Config;
use Rsgrinko\Proton\Support\Logger;
use Throwable;

/**
 * Расписание задач. Живёт внутри воркера, поэтому cron не нужен — а там, где
 * cron есть, тот же круг делает `php bin/proton schedule:run`.
 *
 * Задачи объявляются в config/schedule.php:
 *
 *     Scheduler::every(300, 'cache:cleanup', fn () => Cache::cleanup());
 *     Scheduler::dailyAt('03:30', 'report', fn () => (new DailyReport())->send());
 *
 * Отметки о последнем запуске лежат в settings, поэтому перезапуск воркера
 * не превращается в повторный прогон всего расписания.
 */
final class Scheduler
{
    /** @var array<string, array{interval: int, at: string, callback: callable}> */
    private static array $tasks = [];

    private static bool $booted = false;

    /**
     * Задача раз в N секунд.
     */
    public static function every(int $seconds, string $name, callable $callback): void
    {
        self::$tasks[$name] = ['interval' => max(1, $seconds), 'at' => '', 'callback' => $callback];
    }

    /**
     * Задача раз в сутки в указанное время («03:30»).
     */
    public static function dailyAt(string $time, string $name, callable $callback): void
    {
        self::$tasks[$name] = ['interval' => 86400, 'at' => $time, 'callback' => $callback];
    }

    /**
     * Выполняет те задачи, которым пора. Возвращает имена выполненных.
     *
     * @return array<int, string>
     */
    public static function run(): array
    {
        self::boot();

        $done   = [];
        $logger = new Logger('schedule');

        foreach (self::$tasks as $name => $task) {
            if (!self::due($name, $task)) {
                continue;
            }

            // Отметку ставим до выполнения: упавшая задача не должна повторяться
            // каждую секунду — она подождёт свой следующий срок
            Setting::set(self::key($name), (string) time());

            try {
                ($task['callback'])();

                $done[] = $name;
            } catch (Throwable $e) {
                $logger->error('Задача расписания упала', ['task' => $name, 'error' => $e->getMessage()]);
            }
        }

        return $done;
    }

    /**
     * Все объявленные задачи с временем последнего запуска — показывает состояние.
     *
     * @return array<int, array{name: string, interval: int, at: string, last: string}>
     */
    public static function tasks(): array
    {
        self::boot();

        $result = [];

        foreach (self::$tasks as $name => $task) {
            $last = (int) Setting::get(self::key($name), '0');

            $result[] = [
                'name'     => $name,
                'interval' => $task['interval'],
                'at'       => $task['at'],
                'last'     => $last === 0 ? '' : date('Y-m-d H:i:s', $last),
            ];
        }

        return $result;
    }

    /**
     * Забыть расписание — нужно тестам.
     */
    public static function reset(): void
    {
        self::$tasks  = [];
        self::$booted = false;
    }

    /**
     * Пора ли выполнять задачу.
     *
     * @param array{interval: int, at: string, callback: callable} $task
     */
    private static function due(string $name, array $task): bool
    {
        $last = (int) Setting::get(self::key($name), '0');

        if ($task['at'] === '') {
            return time() - $last >= $task['interval'];
        }

        // Суточная задача: сегодняшний срок наступил, и сегодня её ещё не было
        $due = strtotime(date('Y-m-d ') . $task['at']);

        if ($due === false || time() < $due) {
            return false;
        }

        return $last < $due;
    }

    private static function key(string $name): string
    {
        return 'schedule:' . $name;
    }

    /**
     * Подключает config/schedule.php при первом обращении.
     */
    private static function boot(): void
    {
        if (self::$booted) {
            return;
        }

        self::$booted = true;

        self::core();

        $file = APP_ROOT . '/config/schedule.php';

        if (is_file($file)) {
            require $file;
        }
    }

    /**
     * Задачи ядра. Объявляются до файла приложения, чтобы приложение могло
     * переопределить любую из них своей — задача с тем же именем заменяет.
     */
    private static function core(): void
    {
        $at = trim((string) Config::get('backup.schedule', ''));

        // Копия базы делается, только если для неё задано время
        if ($at !== '') {
            self::dailyAt($at, 'backup:database', static function (): void {
                Queue::push(BackupJob::class);
            });
        }
    }
}
