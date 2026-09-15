<?php

declare(strict_types=1);

/**
 * Список заметок.
 *
 * @var array{items: array<int, \App\Models\Note>, total: int, page: int, pages: int, per_page: int} $page
 * @var \Rsgrinko\Proton\Support\Filters $filters
 */

use Rsgrinko\Proton\Support\ImportPlan;
use Rsgrinko\Proton\Support\ImportReport;
use Rsgrinko\Proton\View\View;

/** @var ImportReport|null $report отчёт после загрузки файла */
$report = View::takeStash('import');

/** @var ImportPlan|null $plan что получится, если подтвердить загрузку */
$plan = View::takeStash('import_plan');

/** @var string $planFile метка отложенного файла */
$planFile = (string) View::takeStash('import_file', '');
?>
<div class="row">
    <h1 style="margin: 0;">Заметки</h1>
    <span class="spacer"></span>

    <?php if (View::can('notes.manage')) { ?>
        <a class="btn primary" href="<?= View::e(View::route('notes.create')) ?>">Новая заметка</a>
    <?php } ?>
</div>

<div style="margin-top: 16px;">
    <?= View::partial('filters', ['filters' => $filters, 'route' => 'notes.index', 'total' => $page['total'], 'submit' => 'Искать', 'export' => 'notes.export']) ?>
</div>

<?php if ($report instanceof ImportReport && $report->errors !== []) { ?>
    <div class="card">
        <h2>Что не загрузилось</h2>

        <ul class="muted small">
            <?php foreach ($report->firstErrors() as $error) { ?>
                <li><?= View::e($error) ?></li>
            <?php } ?>
        </ul>

        <?php if ($report->restErrors() > 0) { ?>
            <p class="muted small">…и ещё строк с ошибками: <?= $report->restErrors() ?></p>
        <?php } ?>
    </div>
<?php } ?>

<?php if ($plan instanceof ImportPlan) { ?>
    <div class="card" style="border-color: var(--accent);">
        <h2>Что получится: <?= View::e($plan->summary()) ?></h2>

        <?php if ($plan->sample !== []) { ?>
            <div class="table-wrap">
                <table class="list">
                    <tr class="head">
                        <th>Строка</th>
                        <th>Что сделаем</th>
                        <th>Название</th>
                        <th class="hide-sm">Причина отказа</th>
                    </tr>

                    <?php foreach ($plan->sample as $row) { ?>
                        <tr>
                            <td class="muted small"><?= (int) $row['line'] ?></td>
                            <td>
                                <span class="badge <?= $row['verdict'] === ImportPlan::SKIP ? 'error' : ($row['verdict'] === ImportPlan::UPDATE ? 'warn' : 'ok') ?>">
                                    <?= View::e(ImportPlan::label((string) $row['verdict'])) ?>
                                </span>
                            </td>
                            <td><?= View::e((string) ($row['data']['title'] ?? '')) ?></td>
                            <td class="hide-sm muted small"><?= View::e((string) $row['error'] ?: '—') ?></td>
                        </tr>
                    <?php } ?>
                </table>
            </div>

            <?php if ($plan->total() > count($plan->sample)) { ?>
                <p class="muted small">Показаны первые <?= count($plan->sample) ?> строк из <?= $plan->total() ?>.</p>
            <?php } ?>
        <?php } ?>

        <?php if ($plan->any() && $planFile !== '') { ?>
            <form method="post" action="<?= View::e(View::route('notes.import.confirm')) ?>">
                <?= View::csrf() ?>
                <input type="hidden" name="file" value="<?= View::e($planFile) ?>">
                <button type="submit" class="primary">Загрузить</button>
                <span class="muted small">Файл уже у нас — присылать заново не нужно</span>
            </form>
        <?php } ?>
    </div>
<?php } ?>

<?php if (View::can('notes.manage')) { ?>
    <div class="card">
        <h2>Загрузка из CSV</h2>

        <p class="muted small">
            Колонки: <span class="mono">Название</span>, <span class="mono">Текст</span>,
            <span class="mono">Закреплена</span> — такие же, как в выгрузке. Сначала покажем,
            что получится: заметка с тем же названием обновится, а не задвоится.
        </p>

        <form method="post" action="<?= View::e(View::route('notes.import')) ?>" enctype="multipart/form-data">
            <?= View::csrf() ?>

            <div class="row">
                <input type="file" name="file" accept=".csv,text/csv" required>
                <button type="submit" class="primary">Посмотреть, что получится</button>
            </div>
        </form>
    </div>
<?php } ?>

<div class="card">
    <?php if ($page['items'] === []) { ?>
        <p class="muted">Пока ничего нет.</p>
    <?php } else { ?>
        <div class="table-wrap">
            <table class="list">
                <tr class="head">
                    <th><?= View::partial('sort', ['filters' => $filters, 'route' => 'notes.index', 'column' => 'title', 'label' => 'Название']) ?></th>
                    <th class="hide-sm">Текст</th>
                    <th class="hide-sm"><?= View::partial('sort', ['filters' => $filters, 'route' => 'notes.index', 'column' => 'created_at', 'label' => 'Создана']) ?></th>
                    <th></th>
                </tr>

                <?php foreach ($page['items'] as $note) { ?>
                    <tr>
                        <td>
                            <a href="<?= View::e(View::route('notes.show', ['id' => $note->id()])) ?>"><?= View::e((string) $note->title) ?></a>
                            <?php if ($note->pinned) { ?><span class="badge info">закреплена</span><?php } ?>
                            <?php if ($note->hasFile()) { ?><span class="badge muted">файл</span><?php } ?>
                        </td>
                        <td class="hide-sm muted small"><?= View::e($note->excerpt(80)) ?></td>
                        <td class="hide-sm muted small"><?= View::e(View::ago((string) $note->raw('created_at'))) ?></td>
                        <td class="right">
                            <?php if (View::can('note.edit', $note)) { ?>
                                <a class="btn small" href="<?= View::e(View::route('notes.edit', ['id' => $note->id()])) ?>">Править</a>
                            <?php } ?>
                        </td>
                    </tr>
                <?php } ?>
            </table>
        </div>

        <?= View::partial('pagination', [
            'route'  => 'notes.index',
            'page'   => $page['page'],
            'pages'  => $page['pages'],
            'params' => $filters->params(),
        ]) ?>
    <?php } ?>
</div>
