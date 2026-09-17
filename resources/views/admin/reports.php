<?php

declare(strict_types=1);

/**
 * Отчёты за период.
 *
 * @var array<string, array{label: string, series: array<string, int>, total: int, max: int}> $reports
 * @var array<string, int> $actions действия в журнале
 * @var array<string, int> $hooks посылки вебхуков по состояниям
 * @var array<string, string> $ranges периоды
 * @var int $days выбранный период
 * @var string $step шаг ряда
 * @var string $from
 * @var string $to
 */

use Rsgrinko\Proton\Models\AuditEntry;
use Rsgrinko\Proton\View\View;

$stepLabel = match ($step) {
    'month' => 'по месяцам',
    'week'  => 'по неделям',
    default => 'по дням',
};
?>
<h1>Отчёты</h1>

<div class="card">
    <div class="row">
        <?php foreach ($ranges as $value => $label) { ?>
            <?php if ((int) $value === $days) { ?>
                <span class="btn small primary"><?= View::e($label) ?></span>
            <?php } else { ?>
                <a class="btn small" href="<?= View::e(View::route('admin.reports', ['days' => $value])) ?>"><?= View::e($label) ?></a>
            <?php } ?>
        <?php } ?>

        <span class="spacer"></span>
        <span class="muted small"><?= View::e($from) ?> — <?= View::e($to) ?>, <?= View::e($stepLabel) ?></span>
    </div>
</div>

<?php foreach ($reports as $key => $report) { ?>
    <div class="card">
        <div class="row">
            <h2 style="margin: 0;"><?= View::e($report['label']) ?></h2>
            <span class="spacer"></span>
            <span class="muted small">всего: <?= (int) $report['total'] ?></span>
            <a class="btn small" href="<?= View::e(View::route('admin.reports.export', ['report' => $key, 'days' => $days])) ?>">CSV</a>
        </div>

        <?php if ($report['total'] === 0) { ?>
            <p class="muted small">За период пусто.</p>
        <?php } else { ?>
            <div class="chart">
                <?php foreach ($report['series'] as $period => $count) { ?>
                    <div class="col<?= $count === 0 ? ' empty' : '' ?>"
                         style="height: <?= max(2, (int) round($count / $report['max'] * 100)) ?>%;"
                         title="<?= View::e($period) ?>: <?= (int) $count ?>"></div>
                <?php } ?>
            </div>

            <div class="chart-legend muted small">
                <span><?= View::e((string) array_key_first($report['series'])) ?></span>
                <span>максимум за период: <?= (int) $report['max'] ?></span>
                <span><?= View::e((string) array_key_last($report['series'])) ?></span>
            </div>
        <?php } ?>
    </div>
<?php } ?>

<div class="card">
    <h2>Действия в журнале</h2>

    <?php if ($actions === []) { ?>
        <p class="muted small">Журнал пуст.</p>
    <?php } else { ?>
        <table class="list">
            <?php foreach ($actions as $action => $count) { ?>
                <tr>
                    <td><?= View::e(AuditEntry::label((string) $action)) ?></td>
                    <td class="right"><?= (int) $count ?></td>
                </tr>
            <?php } ?>
        </table>
    <?php } ?>
</div>

<div class="card">
    <h2>Посылки вебхуков по состояниям</h2>

    <?php if ($hooks === []) { ?>
        <p class="muted small">Посылок не было.</p>
    <?php } else { ?>
        <table class="list">
            <?php foreach ($hooks as $status => $count) { ?>
                <tr>
                    <td><?= View::e((string) $status) ?></td>
                    <td class="right"><?= (int) $count ?></td>
                </tr>
            <?php } ?>
        </table>
    <?php } ?>
</div>
