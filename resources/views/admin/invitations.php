<?php

declare(strict_types=1);

/**
 * Приглашения.
 *
 * @var array{items: array<int, \Rsgrinko\Proton\Models\Invitation>, total: int, page: int, pages: int, per_page: int} $page
 * @var array<int, string> $roles все роли, id => название
 * @var array<int, \Rsgrinko\Proton\Models\Role> $assignable роли, которые этот человек вправе выдать
 * @var array<int, string> $inviters id => логин приглашавшего
 * @var \Rsgrinko\Proton\Support\Filters $filters
 * @var int $days
 */

use Rsgrinko\Proton\Models\Invitation;
use Rsgrinko\Proton\Support\Config;
use Rsgrinko\Proton\View\View;

$badges = [
    Invitation::PENDING  => 'warn',
    Invitation::ACCEPTED => 'ok',
    Invitation::EXPIRED  => 'muted',
    Invitation::REVOKED  => 'muted',
];
?>
<h1>Приглашения</h1>

<div class="card">
    <h2>Пригласить</h2>
    <p class="muted small">
        Человек получит письмо со ссылкой на <?= (int) $days ?> дн. и сам придумает логин и пароль.
        Работает и при закрытой регистрации<?= (bool) Config::get('auth.registration', true) ? '' : ' — сейчас она закрыта' ?>.
    </p>

    <form method="post" action="<?= View::e(View::route('admin.invitations.store')) ?>">
        <?= View::csrf() ?>

        <div class="filters">
            <label>
                <span>Почта</span>
                <input type="email" name="email" required placeholder="anna@example.com">
            </label>

            <label>
                <span>Роль</span>
                <select name="role_id">
                    <?php foreach ($assignable as $role) { ?>
                        <option value="<?= $role->id() ?>"><?= View::e((string) $role->name) ?></option>
                    <?php } ?>
                </select>
            </label>
        </div>

        <div class="filter-actions">
            <button type="submit" class="primary">Отправить</button>
        </div>
    </form>
</div>

<?= View::partial('filters', ['filters' => $filters, 'route' => 'admin.invitations', 'total' => $page['total']]) ?>

<div class="card">
    <?php if ($page['items'] === []) { ?>
        <p class="muted">Приглашений пока нет.</p>
    <?php } else { ?>
        <div class="table-wrap">
            <table class="list">
                <tr class="head">
                    <th><?= View::partial('sort', ['filters' => $filters, 'route' => 'admin.invitations', 'column' => 'email', 'label' => 'Почта']) ?></th>
                    <th>Роль</th>
                    <th>Состояние</th>
                    <th class="hide-sm">Пригласил</th>
                    <th class="hide-sm"><?= View::partial('sort', ['filters' => $filters, 'route' => 'admin.invitations', 'column' => 'expires_at', 'label' => 'Действует до']) ?></th>
                    <th></th>
                </tr>

                <?php foreach ($page['items'] as $invitation) { ?>
                    <?php $state = $invitation->state(); ?>
                    <tr>
                        <td><?= View::e((string) $invitation->email) ?></td>
                        <td data-label="Роль"><?= View::e($roles[(int) $invitation->raw('role_id')] ?? '—') ?></td>
                        <td data-label="Состояние">
                            <span class="badge <?= $badges[$state] ?>"><?= View::e(Invitation::STATES[$state]) ?></span>
                            <?php if ($state === Invitation::ACCEPTED && (int) $invitation->raw('user_id') > 0) { ?>
                                <a class="small" href="<?= View::e(View::route('admin.users.show', ['id' => (int) $invitation->raw('user_id')])) ?>">учётная запись</a>
                            <?php } ?>
                        </td>
                        <td class="hide-sm"><?= View::e($inviters[(int) $invitation->raw('invited_by')] ?? '—') ?></td>
                        <td class="hide-sm muted small"><?= View::e(View::date((string) $invitation->raw('expires_at'), 'd.m.Y H:i')) ?></td>
                        <td class="right">
                            <div class="row end">
                                <?php if ($state !== Invitation::ACCEPTED) { ?>
                                    <form method="post" action="<?= View::e(View::route('admin.invitations.resend', ['id' => $invitation->id()])) ?>">
                                        <?= View::csrf() ?>
                                        <button type="submit">Отправить заново</button>
                                    </form>
                                <?php } ?>

                                <?php if ($state === Invitation::PENDING) { ?>
                                    <form method="post" action="<?= View::e(View::route('admin.invitations.revoke', ['id' => $invitation->id()])) ?>">
                                        <?= View::csrf() ?>
                                        <button type="submit" class="danger">Отозвать</button>
                                    </form>
                                <?php } ?>
                            </div>
                        </td>
                    </tr>
                <?php } ?>
            </table>
        </div>

        <?= View::partial('pagination', [
            'route'  => 'admin.invitations',
            'page'   => $page['page'],
            'pages'  => $page['pages'],
            'params' => $filters->params(),
        ]) ?>
    <?php } ?>
</div>
