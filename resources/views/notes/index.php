<?php

declare(strict_types=1);

/**
 * Список заметок.
 *
 * @var array{items: array<int, \App\Models\Note>, total: int, page: int, pages: int, per_page: int} $page
 * @var string $search
 */

use Rsgrinko\Proton\View\View;
?>
<div class="row">
    <h1 style="margin: 0;">Заметки</h1>
    <span class="spacer"></span>

    <?php if (View::can('notes.manage')) { ?>
        <a class="btn primary" href="<?= View::e(View::route('notes.create')) ?>">Новая заметка</a>
    <?php } ?>
</div>

<div class="card" style="margin-top: 16px;">
    <form method="get" action="<?= View::e(View::route('notes.index')) ?>">
        <div class="filters">
            <label>
                <span>Поиск по названию</span>
                <input type="search" name="q" value="<?= View::e($search) ?>">
            </label>
        </div>

        <div class="filter-actions row">
            <button type="submit" class="primary">Искать</button>
            <?php if ($search !== '') { ?>
                <a class="btn" href="<?= View::e(View::route('notes.index')) ?>">Сбросить</a>
            <?php } ?>
            <span class="spacer"></span>
            <span class="muted small">Всего: <?= (int) $page['total'] ?></span>
        </div>
    </form>
</div>

<div class="card">
    <?php if ($page['items'] === []) { ?>
        <p class="muted">Пока ничего нет.</p>
    <?php } else { ?>
        <div class="table-wrap">
            <table class="list">
                <tr class="head">
                    <th>Название</th>
                    <th class="hide-sm">Текст</th>
                    <th class="hide-sm">Изменена</th>
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
                        <td class="hide-sm muted small"><?= View::e(View::ago((string) $note->raw('updated_at'))) ?></td>
                        <td class="right">
                            <?php if (View::can('notes.manage')) { ?>
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
            'params' => ['q' => $search],
        ]) ?>
    <?php } ?>
</div>
