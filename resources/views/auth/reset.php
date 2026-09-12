<?php

declare(strict_types=1);

/**
 * Новый пароль по ссылке из письма.
 *
 * @var string $token
 */

use Rsgrinko\Proton\Auth\Password;
use Rsgrinko\Proton\View\View;
?>
<h1>Новый пароль</h1>

<div class="card">
    <form method="post" action="<?= View::e(View::route('password.reset', ['token' => $token])) ?>">
        <?= View::csrf() ?>

        <label>
            <span>Пароль (от <?= Password::minLength() ?> символов)</span>
            <input type="password" name="password" autofocus required>
        </label>

        <label>
            <span>Пароль ещё раз</span>
            <input type="password" name="password_confirmation" required>
        </label>

        <p class="muted small">После смены пароля все прежние сеансы будут завершены.</p>

        <button type="submit" class="primary">Сохранить</button>
    </form>
</div>
