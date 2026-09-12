<?php

declare(strict_types=1);

/**
 * Форма входа.
 *
 * @var bool $remember показывать ли галочку «запомнить меня»
 * @var array<string, mixed> $old
 */

use Rsgrinko\Proton\Support\Config;
use Rsgrinko\Proton\View\View;
?>
<h1><?= View::e((string) Config::get('app.name', 'Proton')) ?></h1>

<div class="card">
    <form method="post" action="<?= View::e(View::route('login')) ?>">
        <?= View::csrf() ?>

        <label>
            <span>Логин или почта</span>
            <input type="text" name="login" value="<?= View::e(View::old($old, 'login')) ?>" autofocus required>
        </label>

        <label>
            <span>Пароль</span>
            <input type="password" name="password" required>
        </label>

        <?php if ($remember) { ?>
            <label class="inline" style="margin-bottom: 12px;">
                <input type="checkbox" name="remember" value="1">
                <span>Запомнить меня на этом устройстве</span>
            </label>
        <?php } ?>

        <div class="row">
            <button type="submit" class="primary">Войти</button>
            <span class="spacer"></span>
            <a class="small" href="<?= View::e(View::route('password.forgot')) ?>">Забыли пароль?</a>
        </div>
    </form>
</div>

<?php if ((bool) Config::get('auth.registration', true)) { ?>
    <p class="muted small" style="text-align: center;">
        Нет учётной записи? <a href="<?= View::e(View::route('register')) ?>">Зарегистрироваться</a>
    </p>
<?php } ?>
