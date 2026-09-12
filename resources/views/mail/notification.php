<?php

declare(strict_types=1);

/**
 * Письмо-уведомление: общий вид для всех уведомлений, которым не нужен
 * свой шаблон. Своё письмо делается переопределением mail() у уведомления.
 *
 * @var \Rsgrinko\Proton\Models\User $user
 * @var string $title
 * @var string $body
 * @var string $url куда вести; пусто — без ссылки
 */

use Rsgrinko\Proton\Support\Config;
use Rsgrinko\Proton\View\View;
?>
<p>Здравствуйте<?= trim((string) $user->name) === '' ? '' : ', ' . View::e((string) $user->name) ?>!</p>

<p><b><?= View::e($title) ?></b></p>

<?php if (trim($body) !== '') { ?>
    <p><?= nl2br(View::e($body)) ?></p>
<?php } ?>

<?php if (trim($url) !== '') { ?>
    <p><a href="<?= View::e($url) ?>"><?= View::e($url) ?></a></p>
<?php } ?>

<p class="muted">Это уведомление от <?= View::e((string) Config::get('app.name', 'Proton')) ?>.
Все уведомления видны в самом приложении, в разделе «Уведомления».</p>
