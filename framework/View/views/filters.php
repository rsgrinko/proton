<?php

declare(strict_types=1);

/**
 * Форма фильтров списка. Рисуется по описанию (`Support\Filter`), поэтому
 * новый фильтр появляется в разметке сам — правится только контроллер.
 *
 * @var \Rsgrinko\Proton\Support\Filters $filters
 * @var string $route имя маршрута раздела
 * @var int $total сколько всего нашлось
 * @var string $submit подпись кнопки
 * @var string $export имя маршрута выгрузки, если она есть
 */

use Rsgrinko\Proton\Support\Filter;
use Rsgrinko\Proton\View\View;

$total  = $total ?? -1;
$submit = $submit ?? 'Показать';
$export = $export ?? '';
?>
<div class="card">
    <form method="get" action="<?= View::e(View::route($route)) ?>">
        <?php if ($filters->sort() !== '') { ?>
            <input type="hidden" name="sort" value="<?= View::e($filters->sort()) ?>">
            <input type="hidden" name="dir" value="<?= View::e($filters->direction()) ?>">
        <?php } ?>

        <div class="filters">
            <?php foreach ($filters->fields() as $field) { ?>
                <label class="<?= $field->type === Filter::DATES ? 'dates' : '' ?>">
                    <span><?= View::e($field->label) ?></span>

                    <?php if ($field->type === Filter::SEARCH) { ?>
                        <input type="search" name="<?= View::e($field->key) ?>" value="<?= View::e($filters->value($field->key)) ?>" placeholder="<?= View::e($field->placeholder) ?>">
                    <?php } elseif ($field->type === Filter::TEXT) { ?>
                        <input type="text" name="<?= View::e($field->key) ?>" value="<?= View::e($filters->value($field->key)) ?>" placeholder="<?= View::e($field->placeholder) ?>">
                    <?php } elseif ($field->type === Filter::DATES) { ?>
                        <span class="range">
                            <input type="date" name="<?= View::e($field->key) ?>_from" value="<?= View::e($filters->value($field->key . '_from')) ?>">
                            <input type="date" name="<?= View::e($field->key) ?>_to" value="<?= View::e($filters->value($field->key . '_to')) ?>">
                        </span>
                    <?php } else { ?>
                        <select name="<?= View::e($field->key) ?>">
                            <option value="">неважно</option>
                            <?php foreach ($field->options as $value => $title) { ?>
                                <option value="<?= View::e($value) ?>" <?= $filters->value($field->key) === (string) $value ? 'selected' : '' ?>><?= View::e($title) ?></option>
                            <?php } ?>
                        </select>
                    <?php } ?>
                </label>
            <?php } ?>
        </div>

        <div class="filter-actions row">
            <button type="submit" class="primary"><?= View::e($submit) ?></button>

            <?php if ($filters->active()) { ?>
                <a class="btn" href="<?= View::e(View::route($route)) ?>">Сбросить</a>
            <?php } ?>

            <?php if ($export !== '') { ?>
                <a class="btn" href="<?= View::e(View::route($export, $filters->params())) ?>">Выгрузить CSV</a>
            <?php } ?>

            <span class="spacer"></span>

            <?php if ($total >= 0) { ?>
                <span class="muted small">Всего: <?= (int) $total ?></span>
            <?php } ?>
        </div>
    </form>
</div>
