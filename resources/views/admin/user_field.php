<?php

declare(strict_types=1);

/**
 * Карточка своего поля профиля: название, ключ, тип и подсказка.
 *
 * @var \Rsgrinko\Proton\Models\UserField $field
 * @var array<string, string> $types
 */

use Rsgrinko\Proton\View\View;

$isNew  = !$field->existsInDatabase();
$action = $isNew ? View::route('admin.userFields.create') : View::route('admin.userFields.show', ['id' => $field->id()]);
?>
<h1><?= $isNew ? 'Новое поле профиля' : View::e((string) $field->label) ?></h1>

<div class="card">
    <form method="post" action="<?= View::e($action) ?>">
        <?= View::csrf() ?>

        <label>
            <span>Название</span>
            <input type="text" name="label" value="<?= View::e((string) $field->label) ?>" placeholder="ИД Яндекс.Метрики" <?= $isNew ? 'autofocus' : '' ?> required>
        </label>

        <?php if ($isNew) { ?>
            <label>
                <span>Ключ (латиницей, пусто — подберём по названию)</span>
                <input type="text" name="key" placeholder="ym_id" pattern="[a-z][a-z0-9_]*">
            </label>
        <?php } else { ?>
            <label>
                <span>Ключ</span>
                <input type="text" value="<?= View::e((string) $field->field_key) ?>" disabled>
            </label>
        <?php } ?>

        <label>
            <span>Тип значения</span>
            <select name="type">
                <?php foreach ($types as $code => $title) { ?>
                    <option value="<?= View::e($code) ?>" <?= (string) $field->type === $code ? 'selected' : '' ?>><?= View::e($title) ?></option>
                <?php } ?>
            </select>
        </label>

        <label>
            <span>Подсказка под полем</span>
            <input type="text" name="description" value="<?= View::e((string) $field->description) ?>" placeholder="номер счётчика на metrika.yandex.ru">
        </label>

        <label>
            <span>Порядок показа</span>
            <input type="number" name="sort" value="<?= View::e((string) $field->sort) ?>">
        </label>

        <div class="row">
            <button type="submit" class="primary">Сохранить</button>
            <a class="btn" href="<?= View::e(View::route('admin.userFields')) ?>">К списку</a>
        </div>
    </form>
</div>

<?php if (!$isNew) { ?>
    <div class="card">
        <h2>Удаление</h2>
        <p class="muted small">
            Поле пропадёт из анкеты у всех пользователей вместе со всеми заполненными значениями.
            Обратной дороги нет.
        </p>

        <form method="post" action="<?= View::e(View::route('admin.userFields.delete', ['id' => $field->id()])) ?>">
            <?= View::csrf() ?>
            <button type="submit" class="danger"
                    data-confirm="Удалить поле «<?= View::e((string) $field->label) ?>» и его значения у всех пользователей?">Удалить поле</button>
        </form>
    </div>
<?php } ?>

<?php if ($field->id() > 0) { ?>
    <?= View::partial('history', ['entity' => 'user_field', 'id' => $field->id()]) ?>
<?php } ?>
