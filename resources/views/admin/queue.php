<?php

declare(strict_types=1);

/**
 * Очередь задач и расписание.
 *
 * @var array{items: array<int, array<string, mixed>>, total: int, page: int, pages: int, per_page: int} $page
 * @var \Rsgrinko\Proton\Support\Filters $filters
 * @var array<string, int> $stats
 * @var array<string, string> $statuses
 * @var array<int, array{name: string, interval: int, at: string, last: string}> $schedule
 */

use Rsgrinko\Proton\Queue\Queue;
use Rsgrinko\Proton\View\View;

$badges = [
    Queue::QUEUED  => 'info',
    Queue::RUNNING => 'warn',
    Queue::DONE    => 'ok',
    Queue::FAILED  => 'error',
    Queue::DEAD    => 'error',
];
?>
<h1>Очередь</h1>

<div class="grid cols-4">
    <?php foreach ($statuses as $code => $label) { ?>
        <div class="card stat">
            <div class="label"><?= View::e($label) ?></div>
            <div class="value"><?= (int) ($stats[$code] ?? 0) ?></div>
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
                    <th></th>
                </tr>

                <?php foreach ($schedule as $task) { ?>
                    <tr>
                        <td class="mono small"><?= View::e($task['name']) ?></td>
                        <td class="small"><?= View::e($task['at'] !== '' ? 'ежедневно в ' . $task['at'] : 'раз в ' . $task['interval'] . ' с') ?></td>
                        <td class="muted small"><?= View::e($task['last'] === '' ? 'ни разу' : View::ago($task['last'])) ?></td>
                        <td class="right">
                            <form method="post" action="<?= View::e(View::route('admin.queue.run')) ?>">
                                <?= View::csrf() ?>
                                <input type="hidden" name="task" value="<?= View::e($task['name']) ?>">
                                <button type="submit" data-confirm="Выполнить «<?= View::e($task['name']) ?>» прямо сейчас?">Выполнить</button>
                            </form>
                        </td>
                    </tr>
                <?php } ?>
            </table>
        </div>
    <?php } ?>
</div>

<?= View::partial('filters', ['filters' => $filters, 'route' => 'admin.queue', 'total' => $page['total']]) ?>

<div class="card">
    <h2>Задачи</h2>

    <?php if ($page['items'] === []) { ?>
        <p class="muted">Ничего не нашлось.</p>
    <?php } else { ?>
        <form method="post" action="<?= View::e(View::route('admin.queue.retry')) ?>">
            <?= View::csrf() ?>

            <div class="row bulk-bar">
                <label class="inline">
                    <input type="checkbox" data-check-all>
                    <span class="muted small">отметить все</span>
                </label>

                <button type="submit" data-confirm="Вернуть отмеченные задачи в очередь?">Повторить</button>

                <button type="submit"
                        formaction="<?= View::e(View::route('admin.queue.delete')) ?>"
                        class="danger"
                        data-confirm="Удалить отмеченные задачи? Обратной дороги нет.">Удалить</button>

                <span class="muted small" data-check-count>ничего не отмечено</span>
            </div>

            <div class="table-wrap">
                <table class="list">
                    <tr class="head">
                        <th></th>
                        <th>Задача</th>
                        <th>Очередь</th>
                        <th><?= View::partial('sort', ['filters' => $filters, 'route' => 'admin.queue', 'column' => 'priority', 'label' => 'Важность']) ?></th>
                        <th>Состояние</th>
                        <th class="hide-sm">Попытки</th>
                        <th class="hide-sm"><?= View::partial('sort', ['filters' => $filters, 'route' => 'admin.queue', 'column' => 'created_at', 'label' => 'Создана']) ?></th>
                    </tr>

                    <?php foreach ($page['items'] as $job) { ?>
                        <?php $status = (string) $job['status']; ?>
                        <tr>
                            <td><input type="checkbox" name="ids[]" value="<?= (int) $job['id'] ?>" data-check-item></td>
                            <td>
                                <span class="mono small"><?= View::e((string) $job['job_class']) ?></span>

                                <?php if (trim((string) ($job['error'] ?? '')) !== '') { ?>
                                    <div class="muted small break"><?= View::e(mb_substr((string) $job['error'], 0, 200)) ?></div>
                                <?php } ?>
                            </td>
                            <td class="small"><?= View::e((string) $job['queue']) ?></td>
                            <td class="small"><?= (int) ($job['priority'] ?? 0) ?></td>
                            <td><span class="badge <?= View::e($badges[$status] ?? 'muted') ?>"><?= View::e($statuses[$status] ?? $status) ?></span></td>
                            <td class="hide-sm small"><?= (int) $job['attempts'] ?> из <?= (int) $job['max_attempts'] ?></td>
                            <td class="hide-sm muted small"><?= View::e(View::ago((string) $job['created_at'])) ?></td>
                        </tr>
                    <?php } ?>
                </table>
            </div>
        </form>

        <?= View::partial('pagination', [
            'route'  => 'admin.queue',
            'page'   => $page['page'],
            'pages'  => $page['pages'],
            'params' => $filters->params(),
        ]) ?>
    <?php } ?>
</div>
