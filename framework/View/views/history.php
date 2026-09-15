<?php

declare(strict_types=1);

/**
 * История записи: что с ней делали и кто.
 *
 * Берёт журнал действий по паре «раздел + id», поэтому работает для любой
 * записи, которую пишут через `Audit::created|updated|deleted|action`.
 *
 *     <?= View::partial('history', ['entity' => 'user', 'id' => $user->id()]) ?>
 *
 * @var string $entity раздел журнала: user, role, note
 * @var int|string $id запись
 * @var int $limit сколько показывать
 */

use Rsgrinko\Proton\Models\AuditEntry;
use Rsgrinko\Proton\View\View;

$limit = $limit ?? 10;

// Право смотреть журнал есть не у всех: кто его не имеет, историю не увидит
if (!View::can('audit.view')) {
    return;
}

$entries = AuditEntry::query()
    ->where('entity', $entity)
    ->where('entity_id', (string) $id)
    ->orderBy('id', 'desc')
    ->limit($limit)
    ->get();
?>
<div class="card">
    <h2>История</h2>

    <?php if ($entries === []) { ?>
        <p class="muted small">Записей в журнале нет.</p>
    <?php } else { ?>
        <div class="table-wrap">
            <table class="list">
                <?php foreach ($entries as $entry) { ?>
                    <?php $changes = (array) ($entry->changes ?? []); ?>
                    <tr>
                        <td class="muted small nowrap"><?= View::e(View::date((string) $entry->raw('created_at'))) ?></td>
                        <td class="small"><?= View::e((string) $entry->raw('user_login') ?: 'система') ?></td>
                        <td>
                            <span class="badge muted"><?= View::e(AuditEntry::label((string) $entry->raw('action'))) ?></span>
                            <?= View::e((string) $entry->raw('description')) ?>

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
                    </tr>
                <?php } ?>
            </table>
        </div>

        <p class="muted small">
            <a href="<?= View::e(View::route('admin.audit', ['entity' => $entity])) ?>">весь журнал раздела</a>
        </p>
    <?php } ?>
</div>
