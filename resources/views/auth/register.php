<?php

declare(strict_types=1);

/**
 * Регистрация.
 *
 * @var array<string, mixed> $old
 */

use Rsgrinko\Proton\Auth\Password;
use Rsgrinko\Proton\View\View;
?>
<h1>Регистрация</h1>

<div class="card">
    <form method="post" action="<?= View::e(View::route('register')) ?>">
        <?= View::csrf() ?>

        <label>
            <span>Логин (латиница, цифры, точка, дефис)</span>
            <input type="text" name="login" value="<?= View::e(View::old($old, 'login')) ?>" autofocus required>
        </label>

        <label>
            <span>Почта</span>
            <input type="email" name="email" value="<?= View::e(View::old($old, 'email')) ?>" required>
        </label>

        <label>
            <span>Имя</span>
            <input type="text" name="name" value="<?= View::e(View::old($old, 'name')) ?>">
        </label>

        <label>
            <span>Пароль (от <?= Password::minLength() ?> символов)</span>
            <input type="password" name="password" required>
        </label>

        <label>
            <span>Пароль ещё раз</span>
            <input type="password" name="password_confirmation" required>
        </label>

        <div class="row">
            <button type="submit" class="primary">Зарегистрироваться</button>
            <span class="spacer"></span>
            <a class="small" href="<?= View::e(View::route('login')) ?>">Уже есть учётная запись</a>
        </div>
    </form>
</div>
