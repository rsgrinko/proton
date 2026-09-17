<?php

declare(strict_types=1);

/**
 * Состояние: самопроверка, очередь, расписание, подписки и обслуживание.
 *
 * @var array<int, array{level: string, title: string, value: string, hint: string}> $checks
 * @var string $health
 * @var array{requests: int, average_ms: float, errors: int, slow: int, hours: int} $metrics
 * @var array<string, int|string> $storage
 * @var array<string, int> $queue
 * @var array<int, array{name: string, interval: int, at: string, cron: string, last: string}> $schedule
 * @var array<string, int> $events
 * @var array<string, string> $settings
 * @var array<int, string> $pending
 * @var array{message: string, allow: string, retry: int, since: int}|null $maintenance
 * @var string $php
 */

use Rsgrinko\Proton\Access\Permission;
use Rsgrinko\Proton\Support\Diagnostics;
use Rsgrinko\Proton\Support\Str;
use Rsgrinko\Proton\View\View;

$badges    = [Diagnostics::OK => 'ok', Diagnostics::WARN => 'warn', Diagnostics::ERROR => 'error'];
$labels    = [Diagnostics::OK => 'в порядке', Diagnostics::WARN => 'внимание', Diagnostics::ERROR => 'проблема'];
$canManage = View::can(Permission::SYSTEM_MANAGE);
?>
<h1>Состояние</h1>

<div class="card">
    <h2>Самопроверка</h2>

    <div class="checks">
        <?php foreach ($checks as $check) { ?>
            <div class="item">
                <span class="badge <?= View::e($badges[$check['level']] ?? 'muted') ?>"><?= View::e($labels[$check['level']] ?? $check['level']) ?></span>
                <span class="title"><?= View::e($check['title']) ?></span>
                <span><?= View::e($check['value']) ?></span>

                <?php if ($check['hint'] !== '') { ?>
                    <span class="hint">— <?= View::e($check['hint']) ?></span>
                <?php } ?>
            </div>
        <?php } ?>
    </div>
</div>

<div class="card">
    <h2>Показатели за <?= (int) $metrics['hours'] ?> ч</h2>

    <div class="table-wrap">
        <table>
            <tr>
                <td>Запросов</td>
                <td class="right"><?= (int) $metrics['requests'] ?></td>
                <td>Среднее время ответа</td>
                <td class="right"><?= View::e((string) $metrics['average_ms']) ?> мс</td>
            </tr>
            <tr>
                <td>Ошибок 5xx</td>
                <td class="right"><?= (int) $metrics['errors'] ?></td>
                <td>Медленных ответов</td>
                <td class="right"><?= (int) $metrics['slow'] ?></td>
            </tr>
            <tr>
                <td>База</td>
                <td class="right"><?= View::e(Str::bytes((int) $storage['database_bytes'])) ?></td>
                <td>Каталог var</td>
                <td class="right"><?= View::e(Str::bytes((int) $storage['var_bytes'])) ?></td>
            </tr>
            <tr>
                <td>Свободно на диске</td>
                <td class="right"><?= View::e(Str::bytes((int) $storage['free_bytes'])) ?></td>
                <td colspan="2" class="muted small">Счётчики запросов живут в кэше: очистка кэша их обнуляет</td>
            </tr>
        </table>
    </div>
</div>

<div class="grid cols-2">
    <div class="card">
        <h2>Очередь задач</h2>

        <div class="table-wrap">
            <table>
                <?php foreach (['queued' => 'в очереди', 'running' => 'выполняется', 'done' => 'выполнено', 'failed' => 'с ошибкой'] as $status => $label) { ?>
                    <tr>
                        <td><?= View::e($label) ?></td>
                        <td class="right"><?= (int) ($queue[$status] ?? 0) ?></td>
                    </tr>
                <?php } ?>
            </table>
        </div>

        <?php if (View::can(Permission::SYSTEM_MANAGE)) { ?>
            <div class="row" style="margin-top: 12px;">
                <form method="post" action="<?= View::e(View::route('admin.system.action', ['action' => 'queue-retry'])) ?>">
                    <?= View::csrf() ?>
                    <button type="submit">Повторить упавшие</button>
                </form>

                <form method="post" action="<?= View::e(View::route('admin.system.action', ['action' => 'queue-purge'])) ?>">
                    <?= View::csrf() ?>
                    <button type="submit">Подчистить</button>
                </form>
            </div>
        <?php } ?>
    </div>

    <div class="card">
        <h2>Расписание</h2>

        <?php if ($schedule === []) { ?>
            <p class="muted small">Задачи объявляются в config/schedule.php.</p>
        <?php } else { ?>
            <div class="table-wrap">
                <table class="list">
                    <tr class="head">
                        <th>Задача</th>
                        <th>Когда</th>
                        <th>Последний раз</th>
                    </tr>

                    <?php foreach ($schedule as $task) { ?>
                        <tr>
                            <td class="mono small"><?= View::e($task['name']) ?></td>
                            <td class="small">
                                <?php if ($task['cron'] !== '') { ?>
                                    <span class="mono"><?= View::e($task['cron']) ?></span>
                                <?php } else { ?>
                                    <?= View::e($task['at'] !== '' ? 'ежедневно в ' . $task['at'] : 'раз в ' . $task['interval'] . ' с') ?>
                                <?php } ?>
                            </td>
                            <td class="muted small"><?= View::e($task['last'] === '' ? 'ни разу' : View::ago($task['last'])) ?></td>
                        </tr>
                    <?php } ?>
                </table>
            </div>
        <?php } ?>

        <?php if (View::can(Permission::SYSTEM_MANAGE)) { ?>
            <form method="post" action="<?= View::e(View::route('admin.system.action', ['action' => 'schedule-run'])) ?>" style="margin-top: 12px;">
                <?= View::csrf() ?>
                <button type="submit">Выполнить, что пора</button>
            </form>
        <?php } ?>
    </div>
</div>

<div class="grid cols-2">
    <div class="card">
        <h2>Подписки на события</h2>

        <?php if ($events === []) { ?>
            <p class="muted small">Подписок нет. Они объявляются в config/events.php.</p>
        <?php } else { ?>
            <div class="table-wrap">
                <table>
                    <?php foreach ($events as $event => $count) { ?>
                        <tr>
                            <td class="mono small"><?= View::e($event) ?></td>
                            <td class="right"><?= (int) $count ?></td>
                        </tr>
                    <?php } ?>
                </table>
            </div>
        <?php } ?>
    </div>

    <div class="card">
        <h2>Обслуживание</h2>

        <p class="muted small">
            PHP <?= View::e($php) ?>. После выкладки кода воркер нужно перезапускать:
            он держит загруженные классы в памяти, и задачи продолжали бы выполняться старым кодом.
        </p>

        <?php if (View::can(Permission::SYSTEM_MANAGE)) { ?>
            <div class="row">
                <form method="post" action="<?= View::e(View::route('admin.system.action', ['action' => 'worker-restart'])) ?>">
                    <?= View::csrf() ?>
                    <button type="submit" class="primary">Перезапустить воркер</button>
                </form>

                <form method="post" action="<?= View::e(View::route('admin.system.action', ['action' => 'cache-clear'])) ?>">
                    <?= View::csrf() ?>
                    <button type="submit">Очистить кэш</button>
                </form>

                <?php if ($pending !== []) { ?>
                    <form method="post" action="<?= View::e(View::route('admin.system.action', ['action' => 'migrate'])) ?>">
                        <?= View::csrf() ?>
                        <button type="submit" data-confirm="Применить миграции (<?= count($pending) ?>)? Схема базы изменится.">Применить миграции (<?= count($pending) ?>)</button>
                    </form>
                <?php } ?>
            </div>

            <?php if ($maintenance !== null) { ?>
                <p class="small" style="margin-top: 12px;">
                    <span class="badge warn">включён</span>
                    с <?= View::e(View::ago((string) date('Y-m-d H:i:s', $maintenance['since']))) ?>
                    <?php if ($maintenance['message'] !== '') { ?>
                        — «<?= View::e($maintenance['message']) ?>»
                    <?php } ?>
                    <?php if ($maintenance['allow'] !== '') { ?>
                        <span class="muted">(без ограничения ещё: <?= View::e($maintenance['allow']) ?>)</span>
                    <?php } ?>
                </p>

                <form method="post" action="<?= View::e(View::route('admin.system.action', ['action' => 'maintenance-off'])) ?>" style="margin-top: 8px;">
                    <?= View::csrf() ?>
                    <button type="submit" class="primary">Выключить режим обслуживания</button>
                </form>
            <?php } else { ?>
                <form method="post" action="<?= View::e(View::route('admin.system.action', ['action' => 'maintenance-on'])) ?>" style="margin-top: 12px;">
                    <?= View::csrf() ?>
                    <label>
                        <span>Сообщение посетителям (необязательно)</span>
                        <input type="text" name="maintenance_message" maxlength="191" placeholder="Обновляем базу, вернёмся через 10 минут">
                    </label>
                    <button type="submit" class="danger" data-confirm="Включить режим обслуживания? Сайт станет недоступен всем, кроме system.manage.">Включить режим обслуживания</button>
                </form>
            <?php } ?>
        <?php } else { ?>
            <p class="muted small">Кнопки обслуживания доступны с правом system.manage.</p>
        <?php } ?>

        <?php if ($settings !== []) { ?>
            <details style="margin-top: 12px;">
                <summary class="small">служебные отметки</summary>

                <div class="table-wrap">
                    <table>
                        <?php foreach ($settings as $key => $value) { ?>
                            <tr>
                                <td class="mono small"><?= View::e($key) ?></td>
                                <td class="small break"><?= View::e($value) ?></td>
                            </tr>
                        <?php } ?>
                    </table>
                </div>
            </details>
        <?php } ?>
    </div>
</div>
