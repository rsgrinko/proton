<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Queue;

use Rsgrinko\Proton\Backup\BackupJob;
use Rsgrinko\Proton\Database\Lock;
use Rsgrinko\Proton\Models\ApiToken;
use Rsgrinko\Proton\Models\Setting;
use Rsgrinko\Proton\Models\User;
use Rsgrinko\Proton\Notifications\ApiKeyExpiringNotification;
use Rsgrinko\Proton\Notifications\Notify;
use Rsgrinko\Proton\Support\Config;
use Rsgrinko\Proton\Support\CronExpression;
use Rsgrinko\Proton\Support\Logger;
use Rsgrinko\Proton\Support\Monitor;
use Rsgrinko\Proton\Support\ProtonException;
use Throwable;

/**
 * Расписание задач. Живёт внутри воркера, поэтому cron не нужен — а там, где
 * cron есть, тот же круг делает `php bin/proton schedule:run`.
 *
 * Задачи объявляются в config/schedule.php:
 *
 *     Scheduler::every(300, 'cache:cleanup', fn () => Cache::cleanup());
 *     Scheduler::dailyAt('09:00', 'keys:expiring', fn () => ...);
 *
 * Отметки о последнем запуске лежат в settings, поэтому перезапуск воркера
 * не превращается в повторный прогон всего расписания. От наложения задача
 * защищена так же, как runNow(): пока её держит один процесс, второй просто
 * пропускает свой прогон, а не выполняет её заодно.
 *
 * У every() интервал считается от начала предыдущего запуска: копия каждые
 * 6 часов начинается каждые 6 часов, сколько бы сама ни делалась. Для задачи
 * переменной длины (обошла тысячу записей за минуту, десять тысяч — за
 * четверть часа) это не годится: конец одного прогона может упереться
 * в начало следующего. everyAfterPrevious() считает интервал от конца
 * предыдущего запуска — следующий круг стартует не раньше, чем через
 * положенное время после того, как отработал прошлый.
 *
 * cron() — когда нужно не «раз в N секунд», а «по вторникам и пятницам
 * в 9:00» или «каждые 15 минут с 9 до 18»: пять полей, как в обычном cron,
 * без имён месяцев и дней недели (CronExpression). Задача засчитывается не
 * чаще раза в минуту, даже если run() зовут чаще.
 */
final class Scheduler
{
    /** Интервал считается от начала предыдущего запуска */
    private const MODE_START = 'start';

    /** Интервал считается от конца предыдущего запуска — годится для длинных задач */
    private const MODE_FINISH = 'finish';

    /** @var array<string, array{interval: int, at: string, cron: string, mode: string, callback: callable}> */
    private static array $tasks = [];

    private static bool $booted = false;

    /**
     * Задача раз в N секунд, интервал от начала предыдущего запуска.
     */
    public static function every(int $seconds, string $name, callable $callback): void
    {
        self::register($name, max(1, $seconds), '', '', self::MODE_START, $callback);
    }

    /**
     * Задача раз в N секунд, интервал от конца предыдущего запуска — следующий
     * круг не начнётся, пока не отдохнёт положенное время после прошлого.
     */
    public static function everyAfterPrevious(int $seconds, string $name, callable $callback): void
    {
        self::register($name, max(1, $seconds), '', '', self::MODE_FINISH, $callback);
    }

    /**
     * Задача раз в сутки в указанное время («03:30»).
     */
    public static function dailyAt(string $time, string $name, callable $callback): void
    {
        self::register($name, 86400, $time, '', self::MODE_START, $callback);
    }

    /**
     * Задача по cron-выражению — пять полей: минута час день месяц день-недели.
     * Например: '0 3 * * *' — каждый день в 3:00, '*\/15 9-18 * * 1-5' — каждые
     * 15 минут с 9 до 18 по будням.
     */
    public static function cron(string $expression, string $name, callable $callback): void
    {
        if (!CronExpression::valid($expression)) {
            throw new ProtonException(
                'Негодное cron-выражение у задачи «' . $name . '»: «' . $expression . '» — нужно пять полей '
                . '(минута час день месяц день-недели), числа, *, списки через запятую, диапазоны и шаг /n'
            );
        }

        self::register($name, 0, '', $expression, self::MODE_START, $callback);
    }

    private static function register(string $name, int $interval, string $at, string $cron, string $mode, callable $callback): void
    {
        self::$tasks[$name] = ['interval' => $interval, 'at' => $at, 'cron' => $cron, 'mode' => $mode, 'callback' => $callback];
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

            $lock = new Lock(null, 'schedule:' . $name);

            // Не ждём: воркеров может быть несколько (или воркер и кнопка
            // «Выполнить, что пора» одновременно) — если задачу уже держит
            // другой процесс, свой прогон её просто пропускает, а не дублирует
            if (!$lock->acquire(0)) {
                continue;
            }

            $fromFinish = ($task['mode'] ?? self::MODE_START) === self::MODE_FINISH;

            // Отметку по умолчанию ставим до выполнения: упавшая задача не должна
            // повторяться каждую секунду — она подождёт свой следующий срок.
            // У задачи с интервалом от конца отметка ставится после — в finally,
            // так что и успешный прогон, и упавший получают свой отдых поровну
            if (!$fromFinish) {
                Setting::set(self::key($name), (string) time());
            }

            try {
                ($task['callback'])();

                $done[] = $name;
            } catch (Throwable $e) {
                $logger->error('Задача расписания упала', ['task' => $name, 'error' => $e->getMessage()]);
            } finally {
                if ($fromFinish) {
                    Setting::set(self::key($name), (string) time());
                }

                $lock->release();
            }
        }

        return $done;
    }

    /**
     * Выполнить задачу расписания прямо сейчас, не дожидаясь срока.
     *
     * null — такой задачи нет, false — она уже выполняется (её держит воркер
     * или соседняя вкладка), true — выполнена.
     */
    public static function runNow(string $name): ?bool
    {
        self::boot();

        if (!isset(self::$tasks[$name])) {
            return null;
        }

        $lock = new Lock(null, 'schedule:' . $name);

        // Ждать не будем: раз задача уже идёт, второй прогон не нужен вовсе
        if (!$lock->acquire(0)) {
            return false;
        }

        $fromFinish = (self::$tasks[$name]['mode'] ?? self::MODE_START) === self::MODE_FINISH;

        if (!$fromFinish) {
            Setting::set(self::key($name), (string) time());
        }

        try {
            (self::$tasks[$name]['callback'])();
        } finally {
            if ($fromFinish) {
                Setting::set(self::key($name), (string) time());
            }

            $lock->release();
        }

        return true;
    }

    /**
     * Все объявленные задачи с временем последнего запуска — показывает состояние.
     *
     * @return array<int, array{name: string, interval: int, at: string, cron: string, last: string, fromFinish: bool}>
     */
    public static function tasks(): array
    {
        self::boot();

        $result = [];

        foreach (self::$tasks as $name => $task) {
            $last = (int) Setting::get(self::key($name), '0');

            $result[] = [
                'name'       => $name,
                'interval'   => $task['interval'],
                'at'         => $task['at'],
                'cron'       => $task['cron'] ?? '',
                'last'       => $last === 0 ? '' : date('Y-m-d H:i:s', $last),
                'fromFinish' => ($task['mode'] ?? self::MODE_START) === self::MODE_FINISH,
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

        if (($task['cron'] ?? '') !== '') {
            $now = time();

            // Не чаще раза в минуту — run() может звать чаще, чем раз в 60 с
            return $last < $now - ($now % 60) && CronExpression::matches($task['cron'], $now);
        }

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

        // Ключи API не продлеваются, поэтому владельцу нужно время выпустить
        // новый: напоминаем раз в сутки, пока срок не вышел
        self::dailyAt('09:00', 'keys:expiring', static function (): void {
            $days = max(1, (int) Config::get('security.key_expiry_notice_days', 7));

            foreach (ApiToken::expiringWithin($days) as $token) {
                $owner = User::find((int) $token->raw('user_id'));

                if ($owner === null) {
                    continue;
                }

                Notify::send($owner, new ApiKeyExpiringNotification($token, $token->daysLeft()));
            }
        });

        // Присмотр за порогами: о беде лучше узнать от приложения, чем от людей
        if ((bool) Config::get('monitor.enabled', true)) {
            self::every(
                max(60, (int) Config::get('monitor.interval', 300)),
                'monitor:check',
                static function (): void {
                    Monitor::check();
                }
            );
        }
    }
}
