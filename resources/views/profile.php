<?php

declare(strict_types=1);

/**
 * Свой профиль: данные, свои поля, пароль и устройства.
 *
 * @var \Rsgrinko\Proton\Models\User $user
 * @var array<int, \Rsgrinko\Proton\Models\UserField> $fields свои поля профиля — заведены в панели
 * @var array<int, string> $metaValues значения своих полей: id поля => значение
 * @var array<int, array{id: string, kind: string, ip: string, agent: string, created: string, last: string, current: bool}> $devices
 */

use Rsgrinko\Proton\Auth\Password;
use Rsgrinko\Proton\Models\UserField;
use Rsgrinko\Proton\View\View;
?>
<h1>Профиль</h1>

<div class="card">
    <h2>Фото</h2>

    <div class="row" style="align-items: flex-start;">
        <?= View::avatar($user, true) ?>

        <form method="post" action="<?= View::e(View::route('profile.avatar')) ?>" enctype="multipart/form-data" style="flex: 1; min-width: 220px;">
            <?= View::csrf() ?>

            <label>
                <span>Новый файл — jpg, png, gif или webp</span>
                <input type="file" name="avatar" accept="image/jpeg,image/png,image/gif,image/webp" required>
            </label>

            <div class="row">
                <button type="submit" class="primary"><?= $user->hasAvatar() ? 'Заменить' : 'Загрузить' ?></button>
            </div>
        </form>

        <?php if ($user->hasAvatar()) { ?>
            <form method="post" action="<?= View::e(View::route('profile.avatar.delete')) ?>">
                <?= View::csrf() ?>
                <button type="submit" class="danger" data-confirm="Убрать фото профиля?">Убрать</button>
            </form>
        <?php } ?>
    </div>
</div>

<div class="grid cols-2">
    <div class="card">
        <h2>Данные</h2>

        <form method="post" action="<?= View::e(View::route('profile')) ?>">
            <?= View::csrf() ?>

            <label>
                <span>Логин</span>
                <input type="text" value="<?= View::e((string) $user->login) ?>" disabled>
            </label>

            <label>
                <span>Имя</span>
                <input type="text" name="name" value="<?= View::e((string) $user->name) ?>">
            </label>

            <label>
                <span>Почта</span>
                <input type="email" name="email" value="<?= View::e((string) $user->email) ?>">
            </label>

            <label>
                <span>Телефон</span>
                <input type="tel" name="phone" value="<?= View::e((string) $user->phone) ?>" placeholder="+7 900 000-00-00">
            </label>

            <label>
                <span>Сайт</span>
                <input type="url" name="website" value="<?= View::e((string) $user->website) ?>" placeholder="https://example.com">
            </label>

            <label>
                <span>Должность</span>
                <input type="text" name="position" value="<?= View::e((string) $user->position) ?>">
            </label>

            <label>
                <span>Город</span>
                <input type="text" name="location" value="<?= View::e((string) $user->location) ?>">
            </label>

            <label>
                <span>О себе</span>
                <textarea name="bio" style="min-height: 70px;"><?= View::e((string) $user->bio) ?></textarea>
            </label>

            <?php foreach ($fields as $field) { ?>
                <?php $name = 'meta_' . $field->id(); ?>
                <?php $value = $metaValues[$field->id()] ?? ''; ?>

                <?php if ((string) $field->type === UserField::BOOLEAN) { ?>
                    <label class="inline" style="margin-bottom: 12px;">
                        <input type="checkbox" name="<?= $name ?>" value="1" <?= $value === '1' ? 'checked' : '' ?>>
                        <span><?= View::e((string) $field->label) ?></span>
                    </label>
                <?php } else { ?>
                    <label>
                        <span><?= View::e((string) $field->label) ?></span>

                        <?php if ((string) $field->type === UserField::TEXT) { ?>
                            <textarea name="<?= $name ?>"><?= View::e($value) ?></textarea>
                        <?php } else { ?>
                            <?php $inputType = match ((string) $field->type) {
                                UserField::NUMBER => 'number',
                                UserField::URL    => 'url',
                                UserField::DATE   => 'date',
                                default           => 'text',
                            }; ?>
                            <input type="<?= $inputType ?>" name="<?= $name ?>" value="<?= View::e($value) ?>">
                        <?php } ?>

                        <?php if ((string) $field->description !== '') { ?>
                            <span class="muted small"><?= View::e((string) $field->description) ?></span>
                        <?php } ?>
                    </label>
                <?php } ?>
            <?php } ?>

            <button type="submit" class="primary">Сохранить</button>
        </form>
    </div>

    <div class="card">
        <h2>Пароль</h2>

        <form method="post" action="<?= View::e(View::route('profile.password')) ?>">
            <?= View::csrf() ?>

            <label>
                <span>Текущий пароль</span>
                <input type="password" name="current" required>
            </label>

            <label>
                <span>Новый пароль (от <?= Password::minLength() ?> символов)</span>
                <input type="password" name="password" required>
            </label>

            <label>
                <span>Новый пароль ещё раз</span>
                <input type="password" name="password_confirmation" required>
            </label>

            <p class="muted small">Смена пароля завершит сеансы на остальных устройствах.</p>

            <button type="submit" class="primary">Сменить пароль</button>
        </form>
    </div>
</div>

<div class="card">
    <div class="row">
        <h2 style="margin: 0;">Устройства</h2>
        <span class="spacer"></span>

        <form method="post" action="<?= View::e(View::route('profile.devices.others')) ?>">
            <?= View::csrf() ?>
            <button type="submit">Выйти на остальных устройствах</button>
        </form>
    </div>

    <div class="table-wrap" style="margin-top: 12px;">
        <table class="list">
            <tr class="head">
                <th>Устройство</th>
                <th class="hide-sm">Адрес</th>
                <th class="hide-sm">Начало</th>
                <th>Последний раз</th>
                <th></th>
            </tr>

            <?php foreach ($devices as $device) { ?>
                <tr>
                    <td>
                        <?= View::e(View::device($device['agent'])) ?>
                        <div class="muted small"><?= View::e($device['kind']) ?><?= $device['current'] ? ' — это устройство' : '' ?></div>
                    </td>
                    <td class="hide-sm mono"><?= View::e($device['ip']) ?></td>
                    <td class="hide-sm muted small"><?= View::e(View::date($device['created'])) ?></td>
                    <td class="muted small"><?= View::e(View::ago($device['last'])) ?></td>
                    <td class="right">
                        <?php if (!$device['current']) { ?>
                            <form method="post" action="<?= View::e(View::route('profile.devices.revoke')) ?>">
                                <?= View::csrf() ?>
                                <input type="hidden" name="device" value="<?= View::e($device['id']) ?>">
                                <button type="submit" class="danger">Завершить</button>
                            </form>
                        <?php } ?>
                    </td>
                </tr>
            <?php } ?>
        </table>
    </div>
</div>
