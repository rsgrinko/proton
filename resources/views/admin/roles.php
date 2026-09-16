<?php

declare(strict_types=1);

/**
 * Список ролей.
 *
 * @var array<int, \Rsgrinko\Proton\Models\Role> $roles
 */

use Rsgrinko\Proton\View\View;
?>
<div class="row">
    <h1 style="margin: 0;">Роли</h1>
    <span class="spacer"></span>
    <a class="btn primary" href="<?= View::e(View::route('admin.roles.create')) ?>">Новая роль</a>
</div>

<div class="card" style="margin-top: 16px;">
    <p class="muted small">
        Роль — набор прав под именем. Пользователю выдаётся одна роль: поменяли её —
        поменялось у всех, кому она выдана. Права встроенной роли берутся из кода,
        поэтому новый раздел администратору доступен сразу.
    </p>

    <div class="table-wrap">
        <table class="list">
            <tr class="head">
                <th>Название</th>
                <th class="hide-sm">Описание</th>
                <th>Прав</th>
                <th>Пользователей</th>
            </tr>

            <?php foreach ($roles as $role) { ?>
                <tr>
                    <td>
                        <a href="<?= View::e(View::route('admin.roles.show', ['id' => $role->id()])) ?>"><?= View::e((string) $role->name) ?></a>
                        <?php if ($role->isSystem()) { ?><span class="badge info">встроенная</span><?php } ?>
                    </td>
                    <td class="hide-sm muted small"><?= View::e((string) $role->description) ?></td>
                    <td data-label="Прав"><?= count($role->permissions()) ?></td>
                    <td data-label="Пользователей"><?= $role->usersCount() ?></td>
                </tr>
            <?php } ?>
        </table>
    </div>

    <?= View::partial('pagination', [
        'route'  => 'admin.roles',
        'page'   => $page['page'],
        'pages'  => $page['pages'],
        'params' => [],
    ]) ?>
</div>
