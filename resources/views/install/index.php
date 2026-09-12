<?php

declare(strict_types=1);

/**
 * Веб-установщик: проверки окружения и одна форма со всеми настройками.
 *
 * Шагов нарочно нет: разбивать десяток полей на четыре экрана — значит
 * четыре раза спрашивать подтверждение там, где хватает одной прокрутки.
 *
 * @var array<int, array{ok: bool, title: string, value: string, hint: string}> $checks
 * @var bool $ready
 * @var array<string, string> $values
 * @var array<string, mixed> $old
 */

use Rsgrinko\Proton\Auth\Password;
use Rsgrinko\Proton\View\View;

$value = static function (string $field) use ($values, $old): string {
    return (string) View::old($old, $field, $values[$field] ?? '');
};
?>
<h1>Установка</h1>

<div class="card">
    <h2>Окружение</h2>

    <div class="checks">
        <?php foreach ($checks as $check) { ?>
            <div class="item">
                <span class="badge <?= $check['ok'] ? 'ok' : 'error' ?>"><?= $check['ok'] ? 'в порядке' : 'проблема' ?></span>
                <span class="title"><?= View::e($check['title']) ?></span>
                <span><?= View::e($check['value']) ?></span>

                <?php if ($check['hint'] !== '') { ?>
                    <span class="hint">— <?= View::e($check['hint']) ?></span>
                <?php } ?>
            </div>
        <?php } ?>
    </div>
</div>

<?php if (!$ready) { ?>
    <div class="card">
        <p>Пока окружение не готово, устанавливать нечего. Исправьте отмеченное выше и обновите страницу.</p>
    </div>
<?php } else { ?>
    <form method="post" action="<?= View::e(View::route('install')) ?>">
        <?= View::csrf() ?>

        <div class="card">
            <h2>Приложение</h2>

            <label>
                <span>Название</span>
                <input type="text" name="app_name" value="<?= View::e($value('app_name')) ?>" required>
            </label>

            <label>
                <span>Адрес приложения (по нему собираются ссылки в письмах)</span>
                <input type="text" name="app_url" value="<?= View::e($value('app_url')) ?>" required>
            </label>

            <label>
                <span>Часовой пояс</span>
                <input type="text" name="timezone" value="<?= View::e($value('timezone')) ?>" required>
            </label>
        </div>

        <div class="card">
            <h2>База данных</h2>

            <label>
                <span>Драйвер</span>
                <select name="db_driver">
                    <option value="sqlite" <?= $value('db_driver') === 'sqlite' ? 'selected' : '' ?>>SQLite — файл, ничего настраивать не нужно</option>
                    <option value="mysql" <?= $value('db_driver') === 'mysql' ? 'selected' : '' ?>>MySQL / MariaDB</option>
                </select>
            </label>

            <p class="muted small">
                Поля ниже нужны только для MySQL. Если базы ещё нет, установщик заведёт её сам —
                при условии, что у пользователя есть на это право.
            </p>

            <div class="filters">
                <label>
                    <span>Хост</span>
                    <input type="text" name="db_host" value="<?= View::e($value('db_host')) ?>">
                </label>

                <label>
                    <span>Порт</span>
                    <input type="number" name="db_port" value="<?= View::e($value('db_port')) ?>">
                </label>

                <label>
                    <span>Имя базы</span>
                    <input type="text" name="db_database" value="<?= View::e($value('db_database')) ?>">
                </label>

                <label>
                    <span>Пользователь</span>
                    <input type="text" name="db_username" value="<?= View::e($value('db_username')) ?>">
                </label>

                <label>
                    <span>Пароль</span>
                    <input type="password" name="db_password" autocomplete="new-password">
                </label>
            </div>
        </div>

        <div class="card">
            <h2>Почта</h2>

            <label>
                <span>Чем отправлять</span>
                <select name="mail_driver">
                    <option value="mail" <?= $value('mail_driver') === 'mail' ? 'selected' : '' ?>>mail() — встроенная функция PHP</option>
                    <option value="smtp" <?= $value('mail_driver') === 'smtp' ? 'selected' : '' ?>>SMTP (настройки допишете в .env)</option>
                    <option value="mailer" <?= $value('mail_driver') === 'mailer' ? 'selected' : '' ?>>Внешний почтовый сервис по API</option>
                    <option value="log" <?= $value('mail_driver') === 'log' ? 'selected' : '' ?>>Писать в файлы (разработка)</option>
                    <option value="null" <?= $value('mail_driver') === 'null' ? 'selected' : '' ?>>Никуда не отправлять</option>
                </select>
            </label>

            <label>
                <span>Адрес отправителя</span>
                <input type="email" name="mail_from" value="<?= View::e($value('mail_from')) ?>">
            </label>
        </div>

        <div class="card">
            <h2>Администратор</h2>

            <label>
                <span>Логин</span>
                <input type="text" name="admin_login" value="<?= View::e($value('admin_login')) ?>" required>
            </label>

            <label>
                <span>Почта</span>
                <input type="email" name="admin_email" value="<?= View::e($value('admin_email')) ?>">
            </label>

            <label>
                <span>Пароль (от <?= Password::minLength() ?> символов)</span>
                <input type="password" name="admin_password" autocomplete="new-password" required>
            </label>

            <label>
                <span>Пароль ещё раз</span>
                <input type="password" name="admin_password_confirmation" autocomplete="new-password" required>
            </label>
        </div>

        <div class="card">
            <div class="row">
                <button type="submit" class="primary">Установить</button>
                <span class="muted small">
                    Установщик запишет .env, создаст ключ приложения, накатит схему и заведёт администратора.
                </span>
            </div>
        </div>
    </form>
<?php } ?>
