<?php

declare(strict_types=1);

/**
 * Список пользователей.
 *
 * @var array{items: array<int, \Rsgrinko\Proton\Models\User>, total: int, page: int, pages: int, per_page: int} $page
 * @var array<int, string> $roles
 * @var \Rsgrinko\Proton\Support\Filters $filters
 */

use Rsgrinko\Proton\Access\Permission;
use Rsgrinko\Proton\View\View;

// Список открыт и по users.view — правки и массовые действия показываем
// только тому, у кого есть users.manage
$canManage = View::can(Permission::USERS_MANAGE);
?>
<div class="row">
    <h1 style="margin: 0;">Пользователи</h1>
    <span class="spacer"></span>
    <?php if ($canManage) { ?>
        <a class="btn primary" href="<?= View::e(View::route('admin.users.create')) ?>">Новый пользователь</a>
    <?php } ?>
</div>

<div style="margin-top: 16px;">
    <?= View::partial('filters', ['filters' => $filters, 'route' => 'admin.users', 'total' => $page['total'], 'submit' => 'Искать', 'export' => 'admin.users.export']) ?>
</div>

<div class="card">
    <?php if ($page['items'] === []) { ?>
        <p class="muted">Ничего не нашлось.</p>
    <?php } else { ?>
        <?php if ($canManage) { ?>
        <form method="post" action="<?= View::e(View::route('admin.users.bulk')) ?>">
            <?= View::csrf() ?>

            <?= View::partial('bulk', [
                'actions' => [
                    'enable'  => 'включить',
                    'disable' => 'выключить',
                    'delete'  => 'удалить (в корзину)',
                ],
                'confirm' => 'Выполнить действие над отмеченными пользователями?',
            ]) ?>
        <?php } ?>

        <div class="table-wrap">
            <table class="list">
                <tr class="head">
                    <?php if ($canManage) { ?><th></th><?php } ?>
                    <th></th>
                    <th><?= View::partial('sort', ['filters' => $filters, 'route' => 'admin.users', 'column' => 'login', 'label' => 'Логин']) ?></th>
                    <th class="hide-sm">Имя</th>
                    <th class="hide-sm">Почта</th>
                    <th>Роль</th>
                    <th>Активен</th>
                    <th class="hide-sm"><?= View::partial('sort', ['filters' => $filters, 'route' => 'admin.users', 'column' => 'last_login_at', 'label' => 'Последний вход']) ?></th>
                </tr>

                <?php foreach ($page['items'] as $user) { ?>
                    <tr>
                        <?php if ($canManage) { ?><td><input type="checkbox" name="ids[]" value="<?= $user->id() ?>" data-check-item></td><?php } ?>
                        <td><?= View::avatar($user) ?></td>
                        <td><a href="<?= View::e(View::route('admin.users.show', ['id' => $user->id()])) ?>"><?= View::e((string) $user->login) ?></a></td>
                        <td class="hide-sm"><?= View::e((string) $user->name) ?></td>
                        <td class="hide-sm muted small"><?= View::e((string) $user->email) ?></td>
                        <td data-label="Роль"><?= View::e($roles[(int) $user->raw('role_id')] ?? '—') ?></td>
                        <td data-label="Активен">
                            <?php if ($user->isActive()) { ?>
                                <span class="badge ok">да</span>
                            <?php } else { ?>
                                <span class="badge muted">нет</span>
                            <?php } ?>
                        </td>
                        <td class="hide-sm muted small"><?= View::e(View::ago((string) $user->raw('last_login_at'))) ?></td>
                    </tr>
                <?php } ?>
            </table>
        </div>

        <?php if ($canManage) { ?></form><?php } ?>

        <?= View::partial('pagination', [
            'route'  => 'admin.users',
            'page'   => $page['page'],
            'pages'  => $page['pages'],
            'params' => $filters->params(),
        ]) ?>
    <?php } ?>
</div>
