<?php

declare(strict_types=1);

/**
 * @var string $message
 */

use Rsgrinko\Proton\View\View;
?>
<div class="card">
    <h1>Страница не найдена</h1>
    <p class="muted"><?= View::e($message !== '' ? $message : 'Такого адреса здесь нет.') ?></p>
    <p><a class="btn" href="<?= View::e(View::route('home')) ?>">На главную</a></p>
</div>
