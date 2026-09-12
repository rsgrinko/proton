<?php

declare(strict_types=1);

/**
 * Расписание задач.
 *
 * Выполняет его воркер между разбором очереди, поэтому cron не нужен. Там, где
 * воркера-демона нет, то же самое делает `php bin/proton schedule:run` из cron
 * раз в минуту.
 *
 * Уборку за самим ядром (очередь, счётчики, кэш, токены, журнал, логи) воркер
 * делает сам — сюда пишутся только задачи приложения.
 */

use Rsgrinko\Proton\Queue\Scheduler;
use Rsgrinko\Proton\Support\Logger;

Scheduler::dailyAt('04:00', 'demo:heartbeat', static function (): void {
    (new Logger('schedule'))->info('Суточная задача-пример отработала');
});
