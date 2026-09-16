<?php

declare(strict_types=1);

/**
 * Карточка пользователя — она же форма заведения.
 *
 * @var \Rsgrinko\Proton\Models\User $user
 * @var array<int, \Rsgrinko\Proton\Models\Role> $roles
 * @var array<int, array{id: string, kind: string, ip: string, agent: string, created: string, last: string, current: bool}> $devices
 */

use Rsgrinko\Proton\Access\Permission;
use Rsgrinko\Proton\Auth\Password;
use Rsgrinko\Proton\View\View;

$isNew  = !$user->existsInDatabase();
$action = $isNew ? View::route('admin.users.create') : View::route('admin.users.show', ['id' => $user->id()]);
$hint   = $isNew ? 'пусто — придумаем сами' : 'пусто — не менять';
?>
<h1><?= $isNew ? 'Новый пользователь' : View::e((string) $user->login) ?></h1>

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
                <?php foreach ($roles as $role) { ?>
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

<?php if (!$isNew) { ?>
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

    <?php if (View::can(Permission::USERS_IMPERSONATE) && $user->id() !== View::viewer()->id() && $user->isActive()) { ?>
        <div class="card">
            <h2>Вход под пользователем</h2>
            <p class="muted small">Панель и сайт откроются его правами — свои действия увидите в журнале за него.</p>

            <form method="post" action="<?= View::e(View::route('admin.users.impersonate', ['id' => $user->id()])) ?>">
                <?= View::csrf() ?>
                <button type="submit">Войти как <?= View::e((string) $user->login) ?></button>
            </form>
        </div>
    <?php } ?>

    <div class="card">
        <h2>Удаление</h2>
        <p class="muted small">Пользователь исчезнет вместе со своими сеансами. Его записи останутся.</p>

        <form method="post" action="<?= View::e(View::route('admin.users.delete', ['id' => $user->id()])) ?>">
            <?= View::csrf() ?>
            <button type="submit" class="danger">Удалить пользователя</button>
        </form>
    </div>
<?php } ?>

<?php if ($user->id() > 0) { ?>
    <?= View::partial('history', ['entity' => 'user', 'id' => $user->id()]) ?>
<?php } ?>
