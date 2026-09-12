<?php

declare(strict_types=1);

/**
 * Письмо с подтверждением адреса.
 *
 * @var \Rsgrinko\Proton\Models\User $user
 * @var string $link
 */

use Rsgrinko\Proton\Support\Config;
use Rsgrinko\Proton\View\View;
?>
<p>Здравствуйте<?= trim((string) $user->name) === '' ? '' : ', ' . View::e((string) $user->name) ?>!</p>

<p>Вы зарегистрировались в <?= View::e((string) Config::get('app.name', 'Proton')) ?>.
Чтобы подтвердить адрес, перейдите по ссылке:</p>

<p><a href="<?= View::e($link) ?>"><?= View::e($link) ?></a></p>

<p>Ссылка одноразовая и действует сутки. Если вы ничего не регистрировали — просто удалите это письмо.</p>
