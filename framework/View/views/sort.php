<?php

declare(strict_types=1);

/**
 * Заголовок колонки, по которой можно сортировать. Ссылка тащит за собой
 * текущие фильтры, иначе клик по стрелке сбрасывал бы отбор.
 *
 * @var \Rsgrinko\Proton\Support\Filters $filters
 * @var string $route имя маршрута раздела
 * @var string $column колонка из белого списка сортировки
 * @var string $label подпись
 */

use Rsgrinko\Proton\View\View;

if (!$filters->sortableBy($column)) {
    echo View::e($label);

    return;
}

$current = $filters->sort() === $column;
// По той же колонке — переворачиваем, по новой — начинаем с убывания:
// свежее и большее чаще всего и нужно первым
$direction = $current && $filters->direction() === 'desc' ? 'asc' : 'desc';
$params    = array_merge($filters->params(), ['sort' => $column, 'dir' => $direction]);
?>
<a class="sort<?= $current ? ' current' : '' ?>" href="<?= View::e(View::route($route, $params)) ?>"><?= View::e($label) ?><?php if ($current) { ?><span class="arrow"><?= $filters->direction() === 'asc' ? '↑' : '↓' ?></span><?php } ?></a>
