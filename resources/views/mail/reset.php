<?php

declare(strict_types=1);

/**
 * Письмо со ссылкой на смену пароля.
 *
 * @var \Rsgrinko\Proton\Models\User $user
 * @var string $link
 */

use Rsgrinko\Proton\Support\Config;
use Rsgrinko\Proton\View\View;
?>
<p>Здравствуйте<?= trim((string) $user->name) === '' ? '' : ', ' . View::e((string) $user->name) ?>!</p>

<p>Кто-то попросил сменить пароль в <?= View::e((string) Config::get('app.name', 'Proton')) ?>.
Если это были вы, перейдите по ссылке и задайте новый:</p>

<p><a href="<?= View::e($link) ?>"><?= View::e($link) ?></a></p>

<p>Ссылка одноразовая и действует сутки. После смены пароля все прежние сеансы будут завершены.
Если вы ничего не просили — ничего делать не нужно, пароль останется прежним.</p>
