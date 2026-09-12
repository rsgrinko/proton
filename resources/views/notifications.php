<?php

declare(strict_types=1);

/**
 * Лента уведомлений.
 *
 * @var array{items: array<int, \Rsgrinko\Proton\Models\UserNotification>, total: int, page: int, pages: int, per_page: int} $page
 * @var int $unread сколько непрочитанных
 * @var bool $filter показываем только непрочитанные
 */

use Rsgrinko\Proton\View\View;
?>
<h1>Уведомления<?= $unread > 0 ? ' <span class="badge warn">' . $unread . '</span>' : '' ?></h1>

<div class="card">
    <div class="row">
        <?php if ($filter) { ?>
            <a class="btn" href="<?= View::e(View::route('notifications')) ?>">Показать все</a>
        <?php } else { ?>
            <a class="btn" href="<?= View::e(View::route('notifications', ['unread' => 1])) ?>">Только непрочитанные</a>
        <?php } ?>

        <?php if ($unread > 0) { ?>
            <form method="post" action="<?= View::e(View::route('notifications.read_all')) ?>">
                <?= View::csrf() ?>
                <button type="submit" class="primary">Прочитать всё</button>
            </form>
        <?php } ?>
    </div>
</div>

<div class="card">
    <?php if ($page['items'] === []) { ?>
        <p class="muted"><?= $filter ? 'Непрочитанных нет.' : 'Уведомлений пока нет.' ?></p>
    <?php } else { ?>
        <div class="table-wrap">
            <table class="list">
                <tr class="head">
                    <th>Уведомление</th>
                    <th class="hide-sm">Тип</th>
                    <th>Когда</th>
                    <th></th>
                </tr>

                <?php foreach ($page['items'] as $notification) { ?>
                    <tr>
                        <td>
                            <?php if ($notification->read()) { ?>
                                <?= View::e((string) $notification->title) ?>
                            <?php } else { ?>
                                <b><?= View::e((string) $notification->title) ?></b>
                                <span class="badge warn">новое</span>
                            <?php } ?>

                            <?php if (trim((string) $notification->raw('body')) !== '') { ?>
                                <div class="muted small"><?= nl2br(View::e((string) $notification->raw('body'))) ?></div>
                            <?php } ?>
                        </td>
                        <td class="hide-sm muted mono small"><?= View::e((string) $notification->raw('type')) ?></td>
                        <td class="muted small"><?= View::e(View::ago((string) $notification->raw('created_at'))) ?></td>
                        <td class="right">
                            <div class="row end">
                                <?php if (!$notification->read() || trim((string) $notification->raw('url')) !== '') { ?>
                                    <form method="post" action="<?= View::e(View::route('notifications.read', ['id' => $notification->id()])) ?>">
                                        <?= View::csrf() ?>
                                        <button type="submit">
                                            <?= trim((string) $notification->raw('url')) !== '' ? 'Открыть' : 'Прочитано' ?>
                                        </button>
                                    </form>
                                <?php } ?>

                                <form method="post" action="<?= View::e(View::route('notifications.delete', ['id' => $notification->id()])) ?>">
                                    <?= View::csrf() ?>
                                    <button type="submit" class="danger">Убрать</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php } ?>
            </table>
        </div>

        <?= View::partial('pagination', [
            'route'  => 'notifications',
            'page'   => $page['page'],
            'pages'  => $page['pages'],
            'params' => $filter ? ['unread' => 1] : [],
        ]) ?>
    <?php } ?>
</div>
