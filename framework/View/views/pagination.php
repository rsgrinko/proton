<?php

declare(strict_types=1);

/**
 * Разметка постраничной навигации. Общая для всех списков: новый список —
 * значит и этот кусок к нему.
 *
 * @var string $route имя маршрута раздела
 * @var int $page текущая страница
 * @var int $pages всего страниц
 * @var array<string, mixed> $params текущие фильтры — их нужно тащить по страницам
 */

use Rsgrinko\Proton\View\View;

$params = $params ?? [];

if ($pages <= 1) {
    return;
}

// Показываем не все страницы, а окно вокруг текущей: на сотне страниц
// сплошная лента ссылок бесполезна
$from = max(1, $page - 3);
$to   = min($pages, $page + 3);
?>
<div class="pagination">
    <?php if ($page > 1) { ?>
        <a href="<?= View::e(View::route($route, array_merge($params, ['page' => $page - 1]))) ?>">← назад</a>
    <?php } ?>

    <?php if ($from > 1) { ?>
        <a href="<?= View::e(View::route($route, array_merge($params, ['page' => 1]))) ?>">1</a>
        <?php if ($from > 2) { ?><span class="muted">…</span><?php } ?>
    <?php } ?>

    <?php for ($number = $from; $number <= $to; $number++) { ?>
        <?php if ($number === $page) { ?>
            <span class="current"><?= $number ?></span>
        <?php } else { ?>
            <a href="<?= View::e(View::route($route, array_merge($params, ['page' => $number]))) ?>"><?= $number ?></a>
        <?php } ?>
    <?php } ?>

    <?php if ($to < $pages) { ?>
        <?php if ($to < $pages - 1) { ?><span class="muted">…</span><?php } ?>
        <a href="<?= View::e(View::route($route, array_merge($params, ['page' => $pages]))) ?>"><?= $pages ?></a>
    <?php } ?>

    <?php if ($page < $pages) { ?>
        <a href="<?= View::e(View::route($route, array_merge($params, ['page' => $page + 1]))) ?>">вперёд →</a>
    <?php } ?>
</div>
