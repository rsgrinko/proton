<?php

declare(strict_types=1);

/**
 * Ключи доступа к API.
 *
 * @var array{items: array<int, \Rsgrinko\Proton\Models\ApiToken>, total: int, page: int, pages: int, per_page: int} $page
 * @var array<int, string> $owners
 * @var array<int, \Rsgrinko\Proton\Models\User> $users
 * @var string $issued только что выпущенный ключ — показывается один раз
 */

use Rsgrinko\Proton\View\View;
?>
<h1>Ключи API</h1>

<?php if ($issued !== '') { ?>
    <div class="card" style="border-color: var(--accent);">
        <h2>Новый ключ</h2>
        <p class="muted small">Скопируйте его сейчас: в базе лежит только хеш, показать ключ второй раз будет негде.</p>
        <pre class="break"><?= View::e($issued) ?></pre>
        <p><?= View::copy($issued, 'скопировать ключ') ?></p>
    </div>
<?php } ?>

<div class="card">
    <h2>Выпустить ключ</h2>

    <form method="post" action="<?= View::e(View::route('admin.tokens.store')) ?>">
        <?= View::csrf() ?>

        <div class="filters">
            <label>
                <span>Название</span>
                <input type="text" name="name" placeholder="интеграция с 1С">
            </label>

            <label>
                <span>Владелец</span>
                <select name="user_id">
                    <?php foreach ($users as $user) { ?>
                        <option value="<?= $user->id() ?>"><?= View::e((string) $user->login) ?></option>
                    <?php } ?>
                </select>
            </label>

            <label>
                <span>Разрешённые адреса (через запятую, можно сети)</span>
                <input type="text" name="ips" placeholder="127.0.0.1, 10.0.0.0/8">
            </label>
        </div>

        <div class="filter-actions">
            <button type="submit" class="primary">Выпустить</button>
        </div>
    </form>
</div>

<div class="card">
    <h2>Выпущенные</h2>

    <?php if ($page['items'] === []) { ?>
        <p class="muted">Ключей пока нет.</p>
    <?php } else { ?>
        <div class="table-wrap">
            <table class="list">
                <tr class="head">
                    <th>Название</th>
                    <th>Ключ</th>
                    <th class="hide-sm">Владелец</th>
                    <th class="hide-sm">Адреса</th>
                    <th>Состояние</th>
                    <th class="hide-sm">Использован</th>
                    <th></th>
                </tr>

                <?php foreach ($page['items'] as $token) { ?>
                    <tr>
                        <td><?= View::e((string) $token->name) ?></td>
                        <td class="mono"><?= View::e($token->mask()) ?></td>
                        <td class="hide-sm"><?= View::e($owners[(int) $token->raw('user_id')] ?? '—') ?></td>
                        <td class="hide-sm muted small"><?= View::e((string) $token->raw('allowed_ips') ?: 'без ограничений') ?></td>
                        <td>
                            <?php if ((int) $token->raw('active') === 1) { ?>
                                <span class="badge ok">активен</span>
                            <?php } else { ?>
                                <span class="badge muted">отключён</span>
                            <?php } ?>
                        </td>
                        <td class="hide-sm muted small"><?= View::e(View::ago((string) $token->raw('last_used_at'))) ?></td>
                        <td class="right">
                            <div class="row end">
                                <?php if ((int) $token->raw('active') === 1) { ?>
                                    <form method="post" action="<?= View::e(View::route('admin.tokens.revoke', ['id' => $token->id()])) ?>">
                                        <?= View::csrf() ?>
                                        <button type="submit">Отключить</button>
                                    </form>
                                <?php } ?>

                                <form method="post" action="<?= View::e(View::route('admin.tokens.delete', ['id' => $token->id()])) ?>">
                                    <?= View::csrf() ?>
                                    <button type="submit" class="danger">Удалить</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php } ?>
            </table>
        </div>

        <?= View::partial('pagination', [
            'route'  => 'admin.tokens',
            'page'   => $page['page'],
            'pages'  => $page['pages'],
            'params' => [],
        ]) ?>
    <?php } ?>
</div>
