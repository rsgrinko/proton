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
 *
 * Два способа назначить время:
 *
 *     Scheduler::dailyAt('03:30', 'имя', $колбэк);        // раз в сутки
 *     Scheduler::every(300, 'имя', $колбэк);              // раз в N секунд от начала
 *     Scheduler::everyAfterPrevious(300, 'имя', $колбэк);  // раз в N секунд от конца
 *
 * every() годится для задачи одинаковой длины: копия каждые 6 часов начинается
 * каждые 6 часов, сколько бы сама ни делалась. everyAfterPrevious() — для
 * задачи переменной длины, которая сама продолжает себя следующей задачей
 * очереди (как RebuildNoteSlugsJob ниже): иначе конец одного прогона может
 * упереться в начало следующего.
 */

use App\Jobs\RebuildNoteSlugsJob;
use Rsgrinko\Proton\Queue\Queue;
use Rsgrinko\Proton\Queue\Scheduler;
use Rsgrinko\Proton\Support\Env;
use Rsgrinko\Proton\Support\Logger;

Scheduler::dailyAt('04:00', 'demo:heartbeat', static function (): void {
    (new Logger('schedule'))->info('Суточная задача-пример отработала');
});

// Пересчитывает адресные части всех заметок заново — на случай, если Str::slug()
// когда-нибудь поменяют или slug поправят руками прямо в базе. Выключено по
// умолчанию: полный пересчёт на каждой инсталляции не нужен, включается
// осознанно. Задача продолжает сама себя порциями, пока не разберёт таблицу
// целиком, поэтому интервал — от конца прошлого прогона, а не от начала:
// большая таблица не должна упереться в саму себя
if (Env::bool('NOTES_SLUG_RESYNC', false)) {
    Scheduler::everyAfterPrevious(21600, 'notes:rebuild-slugs', static function (): void {
        Queue::push(RebuildNoteSlugsJob::class, ['from' => 0]);
    });
}
