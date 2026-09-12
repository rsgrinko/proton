<?php

declare(strict_types=1);

/**
 * Запрос ссылки на смену пароля.
 *
 * @var array<string, mixed> $old
 */

use Rsgrinko\Proton\View\View;
?>
<h1>Восстановление пароля</h1>

<div class="card">
    <p class="muted small">Пришлём письмо со ссылкой. Ссылка одноразовая и живёт сутки.</p>

    <form method="post" action="<?= View::e(View::route('password.forgot')) ?>">
        <?= View::csrf() ?>

        <label>
            <span>Почта</span>
            <input type="email" name="email" value="<?= View::e(View::old($old, 'email')) ?>" autofocus required>
        </label>

        <div class="row">
            <button type="submit" class="primary">Отправить</button>
            <span class="spacer"></span>
            <a class="small" href="<?= View::e(View::route('login')) ?>">Вернуться ко входу</a>
        </div>
    </form>
</div>
