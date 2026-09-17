<?php

declare(strict_types=1);

/**
 * Журнал действий.
 *
 * @var array{items: array<int, \Rsgrinko\Proton\Models\AuditEntry>, total: int, page: int, pages: int, per_page: int} $page
 * @var \Rsgrinko\Proton\Support\Filters $filters
 */

use Rsgrinko\Proton\Models\AuditEntry;
use Rsgrinko\Proton\View\View;
?>
<h1>Журнал действий</h1>

<?= View::partial('filters', ['filters' => $filters, 'route' => 'admin.audit', 'total' => $page['total'], 'export' => 'admin.audit.export']) ?>

<div class="card">
    <?php if ($page['items'] === []) { ?>
        <p class="muted">Ничего не нашлось.</p>
    <?php } else { ?>
        <div class="table-wrap">
            <table class="list">
                <tr class="head">
                    <th><?= View::partial('sort', ['filters' => $filters, 'route' => 'admin.audit', 'column' => 'created_at', 'label' => 'Когда']) ?></th>
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
                        <td><span class="badge <?= View::e(AuditEntry::badge((string) $entry->raw('action'))) ?>"><?= View::e(AuditEntry::label((string) $entry->raw('action'))) ?></span></td>
                        <td>
                            <?= View::e((string) $entry->raw('description')) ?>
                            <div class="muted small"><?= View::e((string) $entry->raw('entity')) ?> <?= View::e((string) $entry->raw('entity_id')) ?></div>

                            <?php if ($changes !== []) { ?>
                                <details>
                                    <summary class="small">что изменилось</summary>
                                    <table class="diff">
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
            'params' => $filters->params(),
        ]) ?>
    <?php } ?>
</div>
