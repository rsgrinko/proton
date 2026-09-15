<?php

declare(strict_types=1);

/**
 * Панель массовых действий над списком.
 *
 * Работает в паре с формой вокруг таблицы: в каждой строке галочка
 * `ids[]` с атрибутом data-check-item, здесь — «отметить все» и выбор действия.
 * Отметить все и посчитать отмеченное умеет каркас страницы.
 *
 * @var array<string, string> $actions код => подпись
 * @var string $confirm вопрос перед отправкой
 */

use Rsgrinko\Proton\View\View;

$confirm = $confirm ?? 'Выполнить действие над отмеченными записями?';
?>
<div class="row bulk-bar">
    <label class="inline">
        <input type="checkbox" data-check-all>
        <span class="muted small">отметить все</span>
    </label>

    <select name="action" required>
        <option value="">действие с отмеченными</option>
        <?php foreach ($actions as $code => $label) { ?>
            <option value="<?= View::e($code) ?>"><?= View::e($label) ?></option>
        <?php } ?>
    </select>

    <button type="submit" data-confirm="<?= View::e($confirm) ?>">Выполнить</button>

    <span class="muted small" data-check-count>ничего не отмечено</span>
</div>
