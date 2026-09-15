<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Support;

use Rsgrinko\Proton\Access\Permission;
use Rsgrinko\Proton\Models\Setting;
use Rsgrinko\Proton\Notifications\HealthAlertNotification;
use Rsgrinko\Proton\Notifications\Notify;
use Rsgrinko\Proton\Queue\Queue;
use Throwable;

/**
 * Присмотр за приложением: сравнивает показатели с порогами и, когда что-то
 * вышло за край, шлёт уведомление тем, у кого право system.manage.
 *
 * Зовётся расписанием, то есть воркером. Про одну и ту же беду сообщается
 * не чаще, чем раз в `monitor.repeat_minutes`: сорвавшийся диск не должен
 * превращаться в сотню писем.
 */
final class Monitor
{
    /** Отметки о последних сообщениях */
    private const MARK = 'monitor:alert:';

    /**
     * Проверить пороги и, если надо, сообщить. Возвращает найденные проблемы —
     * их же показывает консольная самопроверка.
     *
     * @return array<string, string> ключ проблемы => что случилось
     */
    public static function check(bool $notify = true): array
    {
        $problems = [];

        $queue = Queue::stats();

        $failed = (int) ($queue['failed'] ?? 0);
        $queued = (int) ($queue['queued'] ?? 0);

        if ($failed >= (int) Config::get('monitor.failed_jobs', 10)) {
            $problems['queue.failed'] = 'Задач с ошибкой в очереди: ' . $failed;
        }

        if ($queued >= (int) Config::get('monitor.queue_backlog', 500)) {
            $problems['queue.backlog'] = 'Очередь не разбирается, ждёт задач: ' . $queued;
        }

        $requests = Metrics::requests(1);

        if ($requests['errors'] >= (int) Config::get('monitor.errors', 20)) {
            $problems['http.errors'] = 'Ошибок 5xx за час: ' . $requests['errors'];
        }

        $free  = (int) (Metrics::storage()['free_bytes'] ?? 0);
        $limit = (int) Config::get('monitor.free_bytes', 500 * 1024 * 1024);

        if ($free > 0 && $free < $limit) {
            $problems['disk.free'] = 'На диске осталось ' . Str::bytes($free);
        }

        if ($notify) {
            foreach ($problems as $key => $message) {
                self::announce($key, $message);
            }
        }

        return $problems;
    }

    /**
     * Сообщить о проблеме, если о ней давно не говорили.
     */
    private static function announce(string $key, string $message): void
    {
        $repeat = max(1, (int) Config::get('monitor.repeat_minutes', 60)) * 60;
        $last   = (int) Setting::get(self::MARK . $key, '0');

        if (time() - $last < $repeat) {
            return;
        }

        Setting::set(self::MARK . $key, (string) time());

        try {
            Notify::toPermission(Permission::SYSTEM_MANAGE, new HealthAlertNotification($key, $message));
        } catch (Throwable $e) {
            (new Logger('monitor'))->error('Не удалось разослать предупреждение', [
                'problem' => $key,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
