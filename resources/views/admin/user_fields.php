<?php

declare(strict_types=1);

/**
 * Список своих полей профиля.
 *
 * @var array<int, \Rsgrinko\Proton\Models\UserField> $fields
 */

use Rsgrinko\Proton\View\View;
?>
<div class="row">
    <h1 style="margin: 0;">Поля профиля</h1>
    <span class="spacer"></span>
    <a class="btn primary" href="<?= View::e(View::route('admin.userFields.create')) ?>">Новое поле</a>
</div>

<div class="card" style="margin-top: 16px;">
    <p class="muted small">
        Своё поле анкеты — без правки кода и миграций: ИД счётчика, табельный номер,
        что угодно. Появляется сразу у каждого пользователя — заполняет он сам, в
        своём профиле; здесь заводится только описание поля, а не значения.
    </p>

    <?php if ($fields === []) { ?>
        <p class="muted">Своих полей пока нет.</p>
    <?php } else { ?>
        <div class="table-wrap">
            <table class="list">
                <tr class="head">
                    <th>Название</th>
                    <th class="hide-sm">Ключ</th>
                    <th>Тип</th>
                    <th class="hide-sm">Подсказка</th>
                </tr>

                <?php foreach ($fields as $field) { ?>
                    <tr>
                        <td>
                            <a href="<?= View::e(View::route('admin.userFields.show', ['id' => $field->id()])) ?>"><?= View::e((string) $field->label) ?></a>
                        </td>
                        <td class="hide-sm mono small"><?= View::e((string) $field->field_key) ?></td>
                        <td data-label="Тип"><?= View::e($field->typeLabel()) ?></td>
                        <td class="hide-sm muted small"><?= View::e((string) $field->description) ?></td>
                    </tr>
                <?php } ?>
            </table>
        </div>
    <?php } ?>
</div>
