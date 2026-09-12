<?php

declare(strict_types=1);

/**
 * Журнал действий.
 *
 * @var array{items: array<int, \Rsgrinko\Proton\Models\AuditEntry>, total: int, page: int, pages: int, per_page: int} $page
 * @var array<string, string> $filters
 * @var array<string, string> $actions
 */

use Rsgrinko\Proton\Models\AuditEntry;
use Rsgrinko\Proton\View\View;
?>
<h1>Журнал действий</h1>

<div class="card">
    <form method="get" action="<?= View::e(View::route('admin.audit')) ?>">
        <div class="filters">
            <label>
                <span>Действие</span>
                <select name="action">
                    <option value="">любое</option>
                    <?php foreach ($actions as $code => $label) { ?>
                        <option value="<?= View::e($code) ?>" <?= $filters['action'] === $code ? 'selected' : '' ?>><?= View::e($label) ?></option>
                    <?php } ?>
                </select>
            </label>

            <label>
                <span>Раздел</span>
                <input type="text" name="entity" value="<?= View::e($filters['entity']) ?>" placeholder="user, role, note">
            </label>

            <label>
                <span>Поиск по описанию</span>
                <input type="search" name="q" value="<?= View::e($filters['q']) ?>">
            </label>
        </div>

        <div class="filter-actions row">
            <button type="submit" class="primary">Показать</button>
            <a class="btn" href="<?= View::e(View::route('admin.audit')) ?>">Сбросить</a>
            <span class="spacer"></span>
            <span class="muted small">Записей: <?= (int) $page['total'] ?></span>
        </div>
    </form>
</div>

<div class="card">
    <?php if ($page['items'] === []) { ?>
        <p class="muted">Ничего не нашлось.</p>
    <?php } else { ?>
        <div class="table-wrap">
            <table class="list">
                <tr class="head">
                    <th>Когда</th>
                    <th>Кто</th>
                    <th>Действие</th>
                    <th>Описание</th>
                    <th class="hide-sm">Адрес</th>
                </tr>

                <?php foreach ($page['items'] as $entry) { ?>
                    <?php $changes = (array) ($entry->changes ?? []); ?>
                    <tr>
                        <td class="muted small nowrap"><?= View::e(View::date((string) $entry->raw('created_at'))) ?></td>
                        <td><?= View::e((string) $entry->raw('user_login') ?: 'система') ?></td>
                        <td><span class="badge muted"><?= View::e(AuditEntry::label((string) $entry->raw('action'))) ?></span></td>
                        <td>
                            <?= View::e((string) $entry->raw('description')) ?>
                            <div class="muted small"><?= View::e((string) $entry->raw('entity')) ?> <?= View::e((string) $entry->raw('entity_id')) ?></div>

                            <?php if ($changes !== []) { ?>
                                <details>
                                    <summary class="small">что изменилось</summary>
                                    <table>
                                        <?php foreach ($changes as $field => $pair) { ?>
                                            <tr>
                                                <td class="muted small"><?= View::e((string) $field) ?></td>
                                                <td class="small break"><?= View::e((string) ($pair[0] ?? '')) ?> → <?= View::e((string) ($pair[1] ?? '')) ?></td>
                                            </tr>
                                        <?php } ?>
                                    </table>
                                </details>
                            <?php } ?>
                        </td>
                        <td class="hide-sm mono small"><?= View::e((string) $entry->raw('ip')) ?></td>
                    </tr>
                <?php } ?>
            </table>
        </div>

        <?= View::partial('pagination', [
            'route'  => 'admin.audit',
            'page'   => $page['page'],
            'pages'  => $page['pages'],
            'params' => $filters,
        ]) ?>
    <?php } ?>
</div>
