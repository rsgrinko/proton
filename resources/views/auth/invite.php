<?php

declare(strict_types=1);

/**
 * Регистрация по приглашению: почта задана приглашением и не меняется.
 *
 * @var string $token
 * @var string $email
 * @var array<string, mixed> $old
 */

use Rsgrinko\Proton\Auth\Password;
use Rsgrinko\Proton\View\View;
?>
<h1>Приглашение</h1>

<div class="card">
    <form method="post" action="<?= View::e(View::route('invite', ['token' => $token])) ?>">
        <?= View::csrf() ?>

        <label>
            <span>Почта</span>
            <input type="email" value="<?= View::e($email) ?>" disabled>
        </label>

        <label>
            <span>Логин (латиница, цифры, точка, дефис)</span>
            <input type="text" name="login" value="<?= View::e(View::old($old, 'login')) ?>" autofocus required>
        </label>

        <label>
            <span>Имя</span>
            <input type="text" name="name" value="<?= View::e(View::old($old, 'name')) ?>">
        </label>

        <label>
            <span>Пароль (от <?= Password::minLength() ?> символов)</span>
            <input type="password" name="password" autocomplete="new-password" required>
        </label>

        <label>
            <span>Пароль ещё раз</span>
            <input type="password" name="password_confirmation" autocomplete="new-password" required>
        </label>

        <div class="row">
            <button type="submit" class="primary">Завести учётную запись</button>
            <span class="spacer"></span>
            <a class="small" href="<?= View::e(View::route('login')) ?>">Уже есть учётная запись</a>
        </div>
    </form>
</div>
