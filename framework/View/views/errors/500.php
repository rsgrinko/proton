<?php

declare(strict_types=1);

/**
 * @var string $message
 * @var string $trace показывается только при включённой отладке
 */

use Rsgrinko\Proton\View\View;

$trace = $trace ?? '';
?>
<div class="card">
    <h1>Что-то сломалось</h1>
    <p><?= View::e($message) ?></p>

    <?php if ($trace !== '') { ?>
        <pre class="break"><?= View::e($trace) ?></pre>
    <?php } ?>

    <p><a class="btn" href="<?= View::e(View::route('home')) ?>">На главную</a></p>
</div>
