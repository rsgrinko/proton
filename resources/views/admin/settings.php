<?php

declare(strict_types=1);

/**
 * Настройки приложения.
 *
 * @var array<string, array<string, array{label: string, type: string, options?: array<string, string>, hint?: string}>> $groups
 * @var array<string, mixed> $values текущие значения
 */

use Rsgrinko\Proton\Access\Permission;
use Rsgrinko\Proton\Support\Settings;
use Rsgrinko\Proton\View\View;

$canManage = View::can(Permission::SETTINGS_MANAGE);
?>
<h1>Настройки</h1>

<div class="card">
    <p class="muted small">
        Здесь то, что меняют на ходу. Значение отсюда ложится поверх <span class="mono">.env</span>;
        пока настройку не трогали, работает значение из файла. Кнопка «Сбросить» убирает
        значение из базы, и настройка снова берётся из <span class="mono">.env</span>.
    </p>
    <p class="muted small">
        Веб-часть подхватывает правку сразу, а воркеру нужен перезапуск
        (<span class="mono">php bin/proton worker:restart</span>): он держит настройки в памяти.
    </p>
    <p class="muted small">
        Ключа приложения и настроек базы здесь нет: их правка может оставить приложение
        без доступа к данным — это работа с <span class="mono">.env</span> и сервером, а не кнопка в браузере.
    </p>
    <?php if ($canManage) { ?>
        <div class="row">
            <a class="btn" href="<?= View::e(View::route('admin.settings.export-env')) ?>">Выгрузить .env</a>
        </div>
    <?php } ?>
</div>

<form method="post" action="<?= View::e(View::route('admin.settings')) ?>">
    <?= View::csrf() ?>
    <fieldset <?= $canManage ? '' : 'disabled' ?> style="border: 0; padding: 0; margin: 0;">

    <?php foreach ($groups as $title => $items) { ?>
        <div class="card">
            <h2><?= View::e($title) ?></h2>

            <?php foreach ($items as $key => $item) { ?>
                <?php $value = $values[$key] ?? null; ?>

                <input type="hidden" name="shown[]" value="<?= View::e($key) ?>">

                <?php if ($item['type'] === Settings::BOOL) { ?>
                    <label class="inline" style="margin-bottom: 10px;">
                        <input type="checkbox" name="settings[<?= View::e($key) ?>]" value="1" <?= (bool) $value ? 'checked' : '' ?>>
                        <span>
                            <?= View::e($item['label']) ?>
                            <span class="muted mono small"><?= View::e($key) ?></span>
                            <?= Settings::overridden($key) ? '<span class="badge info">изменено</span>' : '' ?>
                        </span>
                    </label>
                <?php } else { ?>
                    <label>
                        <span>
                            <?= View::e($item['label']) ?>
                            <span class="mono"><?= View::e($key) ?></span>
                            <?= Settings::overridden($key) ? '<span class="badge info">изменено</span>' : '' ?>
                            <?= isset($item['hint']) ? '— ' . View::e($item['hint']) : '' ?>
                        </span>

                        <?php if ($item['type'] === Settings::SELECT) { ?>
                            <select name="settings[<?= View::e($key) ?>]">
                                <?php foreach ($item['options'] ?? [] as $option => $label) { ?>
                                    <option value="<?= View::e($option) ?>" <?= (string) $value === (string) $option ? 'selected' : '' ?>>
                                        <?= View::e($label) ?>
                                    </option>
                                <?php } ?>
                            </select>
                        <?php } elseif ($item['type'] === Settings::TEXT) { ?>
                            <textarea name="settings[<?= View::e($key) ?>]"><?= View::e((string) $value) ?></textarea>
                        <?php } elseif ($item['type'] === Settings::SECRET) { ?>
                            <input type="password" name="settings[<?= View::e($key) ?>]" value="<?= View::e((string) $value) ?>" autocomplete="off">
                        <?php } elseif ($item['type'] === Settings::INT) { ?>
                            <input type="number" name="settings[<?= View::e($key) ?>]" value="<?= View::e((string) $value) ?>">
                        <?php } else { ?>
                            <input type="text" name="settings[<?= View::e($key) ?>]" value="<?= View::e((string) $value) ?>">
                        <?php } ?>
                    </label>
                <?php } ?>
            <?php } ?>
        </div>
    <?php } ?>

    </fieldset>

    <?php if ($canManage) { ?>
        <div class="card">
            <div class="row">
                <button type="submit" class="primary">Сохранить</button>
            </div>
        </div>
    <?php } ?>
</form>

<?php
$overridden = [];

foreach ($groups as $items) {
    foreach ($items as $key => $item) {
        if (Settings::overridden($key)) {
            $overridden[$key] = $item['label'];
        }
    }
}
?>

<?php if ($overridden !== []) { ?>
    <div class="card">
        <h2>Изменённые настройки</h2>
        <p class="muted small">Эти значения взяты из базы<?= $canManage ? '. Сброс вернёт то, что задано в <span class="mono">.env</span>.' : '.' ?></p>

        <div class="table-wrap">
            <table class="list">
                <tr class="head">
                    <th>Настройка</th>
                    <th>Ключ</th>
                    <?php if ($canManage) { ?><th></th><?php } ?>
                </tr>

                <?php foreach ($overridden as $key => $label) { ?>
                    <tr>
                        <td><?= View::e($label) ?></td>
                        <td class="mono small"><?= View::e($key) ?></td>
                        <?php if ($canManage) { ?>
                        <td class="right">
                            <form method="post" action="<?= View::e(View::route('admin.settings.reset')) ?>">
                                <?= View::csrf() ?>
                                <input type="hidden" name="key" value="<?= View::e($key) ?>">
                                <button type="submit">Сбросить</button>
                            </form>
                        </td>
                        <?php } ?>
                    </tr>
                <?php } ?>
            </table>
        </div>
    </div>
<?php } ?>
