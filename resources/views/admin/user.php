<?php

declare(strict_types=1);

/**
 * Карточка пользователя — она же форма заведения.
 *
 * @var \Rsgrinko\Proton\Models\User $user
 * @var array<int, \Rsgrinko\Proton\Models\Role> $roles все роли — чтобы назвать текущую
 * @var array<int, \Rsgrinko\Proton\Models\Role> $assignable роли, которые смотрящий вправе выдать
 * @var bool $editable права человека целиком покрыты правами смотрящего
 * @var array<int, \Rsgrinko\Proton\Models\UserField> $fields свои поля профиля — заведены в панели
 * @var array<int, string> $metaValues значения своих полей: id поля => значение
 * @var array<int, array{id: string, kind: string, ip: string, agent: string, created: string, last: string, current: bool}> $devices
 */

use Rsgrinko\Proton\Access\Permission;
use Rsgrinko\Proton\Auth\Password;
use Rsgrinko\Proton\Models\UserField;
use Rsgrinko\Proton\View\View;

$isNew     = !$user->existsInDatabase();
$action    = $isNew ? View::route('admin.users.create') : View::route('admin.users.show', ['id' => $user->id()]);
$hint      = $isNew ? 'пусто — придумаем сами' : 'пусто — не менять';
// Новый — только у тех, кто прошёл can:users.manage в маршруте; карточку же
// существующего человека открывает и users.view — форму правки там не покажем
// Того, у кого прав больше, не правят, не удаляют и не входят под ним:
// контроллер это всё равно не пропустит, а кнопки не должны обещать лишнего
$canManage = View::can(Permission::USERS_MANAGE) && $editable;

$roleName = '—';
foreach ($roles as $role) {
    if ($role->id() === (int) $user->raw('role_id')) {
        $roleName = (string) $role->name;

        break;
    }
}
?>
<div class="row">
    <?php if (!$isNew) { ?><?= View::avatar($user) ?><?php } ?>
    <h1 style="margin: 0;"><?= $isNew ? 'Новый пользователь' : View::e((string) $user->login) ?></h1>
</div>

<?php if ($isNew || $canManage) { ?>
<div class="card">
    <form method="post" action="<?= View::e($action) ?>">
        <?= View::csrf() ?>

        <?php if ($isNew) { ?>
            <label>
                <span>Логин</span>
                <input type="text" name="login" autofocus required>
            </label>
        <?php } ?>

        <label>
            <span>Имя</span>
            <input type="text" name="name" value="<?= View::e((string) $user->name) ?>">
        </label>

        <label>
            <span>Почта</span>
            <input type="email" name="email" value="<?= View::e((string) $user->email) ?>">
        </label>

        <label>
            <span>Роль</span>
            <select name="role_id">
                <?php foreach ($assignable as $role) { ?>
                    <option value="<?= $role->id() ?>" <?= (int) $user->raw('role_id') === $role->id() ? 'selected' : '' ?>>
                        <?= View::e((string) $role->name) ?>
                    </option>
                <?php } ?>
            </select>
        </label>

        <label>
            <span>Пароль (<?= View::e($hint) ?>, от <?= Password::minLength() ?> символов)</span>
            <input type="password" name="password" autocomplete="new-password">
        </label>

        <label class="inline" style="margin-bottom: 12px;">
            <input type="checkbox" name="active" value="1" <?= $isNew || $user->isActive() ? 'checked' : '' ?>>
            <span>Активен</span>
        </label>

        <div class="row">
            <button type="submit" class="primary">Сохранить</button>
            <a class="btn" href="<?= View::e(View::route('admin.users')) ?>">К списку</a>
        </div>
    </form>
</div>
<?php } else { ?>
<div class="card">
    <dl class="props">
        <dt>Логин</dt>
        <dd><?= View::e((string) $user->login) ?></dd>

        <dt>Имя</dt>
        <dd><?= $user->name !== '' ? View::e((string) $user->name) : '<span class="muted">—</span>' ?></dd>

        <dt>Почта</dt>
        <dd><?= $user->email !== '' ? View::e((string) $user->email) : '<span class="muted">—</span>' ?></dd>

        <dt>Роль</dt>
        <dd><?= View::e($roleName) ?></dd>

        <dt>Активен</dt>
        <dd><?= $user->isActive() ? 'да' : 'нет' ?></dd>
    </dl>

    <p style="margin-top: 12px;"><a class="btn" href="<?= View::e(View::route('admin.users')) ?>">К списку</a></p>
</div>
<?php } ?>

<?php if (!$isNew) { ?>
    <div class="card">
        <h2>Профиль</h2>
        <p class="muted small">Необязательные поля — заполняет сам человек в своём профиле.</p>

        <dl class="props">
            <dt>Телефон</dt>
            <dd><?= $user->phone !== '' ? View::e((string) $user->phone) : '<span class="muted">—</span>' ?></dd>

            <dt>Сайт</dt>
            <dd><?php if ($user->website !== '') { ?><a href="<?= View::e((string) $user->website) ?>" target="_blank" rel="noopener"><?= View::e((string) $user->website) ?></a><?php } else { ?><span class="muted">—</span><?php } ?></dd>

            <dt>Должность</dt>
            <dd><?= $user->position !== '' ? View::e((string) $user->position) : '<span class="muted">—</span>' ?></dd>

            <dt>Город</dt>
            <dd><?= $user->location !== '' ? View::e((string) $user->location) : '<span class="muted">—</span>' ?></dd>

            <dt>О себе</dt>
            <dd><?= $user->bio !== '' ? nl2br(View::e((string) $user->bio)) : '<span class="muted">—</span>' ?></dd>

            <?php foreach ($fields as $field) { ?>
                <?php $value = $metaValues[$field->id()] ?? ''; ?>
                <dt><?= View::e((string) $field->label) ?></dt>
                <dd>
                    <?php if ($value === '') { ?>
                        <span class="muted">—</span>
                    <?php } elseif ((string) $field->type === UserField::BOOLEAN) { ?>
                        <?= $value === '1' ? 'да' : 'нет' ?>
                    <?php } elseif ((string) $field->type === UserField::URL) { ?>
                        <a href="<?= View::e($value) ?>" target="_blank" rel="noopener"><?= View::e($value) ?></a>
                    <?php } else { ?>
                        <?= nl2br(View::e($value)) ?>
                    <?php } ?>
                </dd>
            <?php } ?>
        </dl>
    </div>

    <div class="card">
        <h2>Устройства</h2>

        <?php if ($devices === []) { ?>
            <p class="muted">Активных сеансов нет.</p>
        <?php } else { ?>
            <div class="table-wrap">
                <table class="list">
                    <tr class="head">
                        <th>Устройство</th>
                        <th class="hide-sm">Адрес</th>
                        <th>Последний раз</th>
                    </tr>

                    <?php foreach ($devices as $device) { ?>
                        <tr>
                            <td>
                                <?= View::e(View::device($device['agent'])) ?>
                                <div class="muted small"><?= View::e($device['kind']) ?></div>
                            </td>
                            <td class="hide-sm mono"><?= View::e($device['ip']) ?></td>
                            <td class="muted small"><?= View::e(View::ago($device['last'])) ?></td>
                        </tr>
                    <?php } ?>
                </table>
            </div>
        <?php } ?>
    </div>

    <?php if (View::can(Permission::USERS_IMPERSONATE) && $editable && $user->id() !== View::viewer()->id() && $user->isActive()) { ?>
        <div class="card">
            <h2>Вход под пользователем</h2>
            <p class="muted small">Панель и сайт откроются его правами — свои действия увидите в журнале за него.</p>

            <form method="post" action="<?= View::e(View::route('admin.users.impersonate', ['id' => $user->id()])) ?>">
                <?= View::csrf() ?>
                <button type="submit">Войти как <?= View::e((string) $user->login) ?></button>
            </form>
        </div>
    <?php } ?>

    <?php if ($canManage) { ?>
        <div class="card">
            <h2>Удаление</h2>
            <p class="muted small">Пользователь исчезнет вместе со своими сеансами. Его записи останутся.</p>

            <form method="post" action="<?= View::e(View::route('admin.users.delete', ['id' => $user->id()])) ?>">
                <?= View::csrf() ?>
                <button type="submit" class="danger">Удалить пользователя</button>
            </form>
        </div>
    <?php } ?>
<?php } ?>

<?php if ($user->id() > 0) { ?>
    <?= View::partial('history', ['entity' => 'user', 'id' => $user->id()]) ?>
<?php } ?>
