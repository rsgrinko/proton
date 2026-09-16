<?php

declare(strict_types=1);

/**
 * Корзина: удалённые записи, которые ещё можно вернуть.
 *
 * @var array<string, array{model: string, label: string, title: callable, permission: string}> $kinds
 * @var array<string, int> $counts
 * @var string $kind выбранный раздел
 * @var string $label его подпись
 * @var callable $title как назвать запись
 * @var array{items: array<int, \Rsgrinko\Proton\Database\Model\Model>, total: int, page: int, pages: int, per_page: int} $page
 */

use Rsgrinko\Proton\Database\Model\Model;
use Rsgrinko\Proton\View\View;
?>
<h1>Корзина</h1>

<div class="card">
    <div class="row">
        <?php foreach ($kinds as $key => $item) { ?>
            <?php if ($key === $kind) { ?>
                <span class="badge info"><?= View::e($item['label']) ?>: <?= (int) ($counts[$key] ?? 0) ?></span>
            <?php } else { ?>
                <a class="btn small" href="<?= View::e(View::route('admin.trash', ['kind' => $key])) ?>">
                    <?= View::e($item['label']) ?> (<?= (int) ($counts[$key] ?? 0) ?>)
                </a>
            <?php } ?>
        <?php } ?>

        <span class="spacer"></span>
        <span class="muted small">Корзина не архив: слишком старое воркер добивает сам</span>
    </div>
</div>

<div class="card">
    <div class="row">
        <h2 style="margin: 0;"><?= View::e($label) ?></h2>
        <span class="spacer"></span>

        <?php if (($counts[$kind] ?? 0) > 0) { ?>
            <form method="post" action="<?= View::e(View::route('admin.trash.clear')) ?>">
                <?= View::csrf() ?>
                <input type="hidden" name="kind" value="<?= View::e($kind) ?>">
                <button type="submit" class="danger"
                        data-confirm="Очистить корзину «<?= View::e($label) ?>» целиком? Удалит всё, не только эту страницу — обратной дороги нет.">
                    Очистить корзину
                </button>
            </form>
        <?php } ?>
    </div>

    <?php if ($page['items'] === []) { ?>
        <p class="muted">Здесь пусто — ничего не удаляли или всё уже вернули.</p>
    <?php } else { ?>
        <form method="post" action="<?= View::e(View::route('admin.trash.bulk')) ?>">
            <?= View::csrf() ?>
            <input type="hidden" name="kind" value="<?= View::e($kind) ?>">

            <?= View::partial('bulk', [
                'actions' => [
                    'restore' => 'вернуть',
                    'destroy' => 'удалить совсем',
                ],
                'confirm' => 'Выполнить действие над отмеченными записями? Удаление совсем — без возврата.',
            ]) ?>

        <div class="table-wrap">
            <table class="list">
                <tr class="head">
                    <th></th>
                    <th>Запись</th>
                    <th>Удалена</th>
                    <th></th>
                </tr>

                <?php foreach ($page['items'] as $record) { ?>
                    <tr>
                        <td><input type="checkbox" name="ids[]" value="<?= $record->id() ?>" data-check-item></td>
                        <td><?= View::e(($title)($record)) ?></td>
                        <td class="muted small nowrap"><?= View::e(View::ago((string) $record->raw(Model::DELETED_AT))) ?></td>
                        <td class="right">
                            <div class="row end">
                                <form method="post" action="<?= View::e(View::route('admin.trash.restore')) ?>">
                                    <?= View::csrf() ?>
                                    <input type="hidden" name="kind" value="<?= View::e($kind) ?>">
                                    <input type="hidden" name="id" value="<?= $record->id() ?>">
                                    <button type="submit" class="primary">Вернуть</button>
                                </form>

                                <form method="post" action="<?= View::e(View::route('admin.trash.destroy')) ?>">
                                    <?= View::csrf() ?>
                                    <input type="hidden" name="kind" value="<?= View::e($kind) ?>">
                                    <input type="hidden" name="id" value="<?= $record->id() ?>">
                                    <button type="submit" class="danger">Удалить совсем</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php } ?>
            </table>
        </div>
        </form>

        <?= View::partial('pagination', [
            'route'  => 'admin.trash',
            'page'   => $page['page'],
            'pages'  => $page['pages'],
            'params' => ['kind' => $kind],
        ]) ?>
    <?php } ?>
</div>
