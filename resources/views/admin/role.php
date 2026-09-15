<?php

declare(strict_types=1);

/**
 * Карточка роли: название и галочки прав по разделам.
 *
 * @var \Rsgrinko\Proton\Models\Role $role
 * @var array<string, array<string, string>> $groups права по разделам
 */

use Rsgrinko\Proton\View\View;

$isNew   = !$role->existsInDatabase();
$action  = $isNew ? View::route('admin.roles.create') : View::route('admin.roles.show', ['id' => $role->id()]);
$granted = $role->permissions();
?>
<h1><?= $isNew ? 'Новая роль' : View::e((string) $role->name) ?></h1>

<div class="card">
    <form method="post" action="<?= View::e($action) ?>">
        <?= View::csrf() ?>

        <label>
            <span>Название</span>
            <input type="text" name="name" value="<?= View::e((string) $role->name) ?>" <?= $isNew ? 'autofocus' : '' ?> required>
        </label>

        <label>
            <span>Описание</span>
            <input type="text" name="description" value="<?= View::e((string) $role->description) ?>">
        </label>

        <?php if ($role->isSystem()) { ?>
            <p class="muted small">
                Это встроенная роль: её права берутся из кода и включают всё, что появится в дальнейшем.
                Менять их нельзя — иначе после нового раздела администратор остался бы без доступа к нему.
            </p>
        <?php } else { ?>
            <h2 style="margin-top: 16px;">Права</h2>

            <?php foreach ($groups as $title => $permissions) { ?>
                <div class="card">
                    <h2><?= View::e($title) ?></h2>

                    <?php foreach ($permissions as $code => $label) { ?>
                        <label class="inline" style="margin-bottom: 6px;">
                            <input type="checkbox" name="permissions[]" value="<?= View::e($code) ?>"
                                <?= in_array($code, $granted, true) ? 'checked' : '' ?>>
                            <span><?= View::e($label) ?> <span class="muted mono"><?= View::e($code) ?></span></span>
                        </label>
                    <?php } ?>
                </div>
            <?php } ?>
        <?php } ?>

        <div class="row">
            <button type="submit" class="primary">Сохранить</button>
            <a class="btn" href="<?= View::e(View::route('admin.roles')) ?>">К списку</a>
        </div>
    </form>
</div>

<?php if (!$isNew && !$role->isSystem()) { ?>
    <div class="card">
        <h2>Удаление</h2>
        <p class="muted small">Роль удаляется, только если она никому не выдана.</p>

        <form method="post" action="<?= View::e(View::route('admin.roles.delete', ['id' => $role->id()])) ?>">
            <?= View::csrf() ?>
            <button type="submit" class="danger">Удалить роль</button>
        </form>
    </div>
<?php } ?>

<?php if ($role->id() > 0) { ?>
    <?= View::partial('history', ['entity' => 'role', 'id' => $role->id()]) ?>
<?php } ?>
