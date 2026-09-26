<?php

declare(strict_types=1);

/**
 * Письмо с приглашением.
 *
 * @var \Rsgrinko\Proton\Models\User $inviter
 * @var \Rsgrinko\Proton\Models\Role|null $role
 * @var string $link
 * @var int $days
 */

use Rsgrinko\Proton\Support\Config;
use Rsgrinko\Proton\View\View;

$who = trim((string) $inviter->name) !== '' ? (string) $inviter->name : (string) $inviter->login;
?>
<p>Здравствуйте!</p>

<p><?= View::e($who) ?> приглашает вас в <?= View::e((string) Config::get('app.name', 'Proton')) ?><?= $role !== null ? ' — роль «' . View::e((string) $role->name) . '»' : '' ?>.
Чтобы завести учётную запись, перейдите по ссылке:</p>

<p><a href="<?= View::e($link) ?>"><?= View::e($link) ?></a></p>

<p>Ссылка одноразовая и действует <?= (int) $days ?> дн. Если приглашения вы не ждали — просто удалите это письмо.</p>
