<?php

declare(strict_types=1);

/**
 * События, на которые можно подписать вебхук.
 *
 * События ядра (user.registered, user.login, user.logout, webhook.test)
 * объявлены в Webhooks и здесь не повторяются. Сюда добавляются только свои —
 * вместе с кодом, который их объявляет через Events::fire(). Миграция для этого
 * не нужна: в базе хранятся только подписки.
 *
 * Чтобы событие уезжало подписчикам само, реестр вешается на шину событий —
 * строка Webhooks::subscribe() в config/events.php.
 */

use Rsgrinko\Proton\Webhooks\Webhooks;

Webhooks::register('note.created', 'Заметка создана', 'Заметки');
Webhooks::register('note.updated', 'Заметка изменена', 'Заметки');
Webhooks::register('note.deleted', 'Заметка удалена', 'Заметки');
