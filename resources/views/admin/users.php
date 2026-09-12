<?php

declare(strict_types=1);

/**
 * Список пользователей.
 *
 * @var array{items: array<int, \Rsgrinko\Proton\Models\User>, total: int, page: int, pages: int, per_page: int} $page
 * @var array<int, string> $roles
 * @var string $search
 */

use Rsgrinko\Proton\View\View;
?>
<div class="row">
    <h1 style="margin: 0;">Пользователи</h1>
    <span class="spacer"></span>
    <a class="btn primary" href="<?= View::e(View::route('admin.users.create')) ?>">Новый пользователь</a>
</div>

<div class="card" style="margin-top: 16px;">
    <form method="get" action="<?= View::e(View::route('admin.users')) ?>">
        <div class="filters">
            <label>
                <span>Поиск по логину или почте</span>
                <input type="search" name="q" value="<?= View::e($search) ?>">
            </label>
        </div>

        <div class="filter-actions row">
            <button type="submit" class="primary">Искать</button>
            <?php if ($search !== '') { ?>
                <a class="btn" href="<?= View::e(View::route('admin.users')) ?>">Сбросить</a>
            <?php } ?>
            <span class="spacer"></span>
            <span class="muted small">Всего: <?= (int) $page['total'] ?></span>
        </div>
    </form>
</div>

<div class="card">
    <div class="table-wrap">
        <table class="list">
            <tr class="head">
                <th>Логин</th>
                <th class="hide-sm">Имя</th>
                <th class="hide-sm">Почта</th>
                <th>Роль</th>
                <th>Активен</th>
                <th class="hide-sm">Последний вход</th>
            </tr>

            <?php foreach ($page['items'] as $user) { ?>
                <tr>
                    <td><a href="<?= View::e(View::route('admin.users.show', ['id' => $user->id()])) ?>"><?= View::e((string) $user->login) ?></a></td>
                    <td class="hide-sm"><?= View::e((string) $user->name) ?></td>
                    <td class="hide-sm muted small"><?= View::e((string) $user->email) ?></td>
                    <td><?= View::e($roles[(int) $user->raw('role_id')] ?? '—') ?></td>
                    <td>
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

    <?= View::partial('pagination', [
        'route'  => 'admin.users',
        'page'   => $page['page'],
        'pages'  => $page['pages'],
        'params' => ['q' => $search],
    ]) ?>
</div>
