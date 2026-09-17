<?php

declare(strict_types=1);

/**
 * Обзор панели.
 *
 * @var array<string, array{title: string, value?: int|string, hint?: string, route?: string, badge?: string, badgeLabel?: string}> $widgets
 * @var array<int, \Rsgrinko\Proton\Models\AuditEntry> $events
 * @var bool $canSeeAudit
 */

use Rsgrinko\Proton\Models\AuditEntry;
use Rsgrinko\Proton\View\View;

/**
 * Нутро карточки — общее что для ссылки, что для простого блока.
 */
$inner = static function (array $widget): string {
    ob_start();
    ?>
    <div class="label"><?= View::e($widget['title']) ?></div>

    <?php if (isset($widget['badge'])) { ?>
        <div class="value"><span class="badge <?= View::e($widget['badge']) ?>"><?= View::e((string) ($widget['badgeLabel'] ?? $widget['badge'])) ?></span></div>
    <?php } else { ?>
        <div class="value"><?= View::e((string) ($widget['value'] ?? '—')) ?></div>
    <?php } ?>

    <?php if (($widget['hint'] ?? '') !== '') { ?>
        <div class="muted small"><?= View::e((string) $widget['hint']) ?></div>
    <?php } ?>
    <?php

    return (string) ob_get_clean();
};
?>
<h1>Панель</h1>

<?php if ($widgets === []) { ?>
    <p class="muted">Для вашей роли на обзоре нечего показать.</p>
<?php } else { ?>
    <div class="grid cols-4">
        <?php foreach ($widgets as $widget) { ?>
            <?php if (($widget['route'] ?? '') !== '') { ?>
                <a class="card stat" href="<?= View::e(View::route($widget['route'])) ?>"><?= $inner($widget) ?></a>
            <?php } else { ?>
                <div class="card stat"><?= $inner($widget) ?></div>
            <?php } ?>
        <?php } ?>
    </div>
<?php } ?>

<?php if ($canSeeAudit) { ?>
    <div class="card">
        <h2>Последние действия</h2>

        <?php if ($events === []) { ?>
            <p class="muted">Журнал пуст.</p>
        <?php } else { ?>
            <div class="table-wrap">
                <table class="list">
                    <tr class="head">
                        <th>Когда</th>
                        <th>Кто</th>
                        <th>Что</th>
                    </tr>

                    <?php foreach ($events as $event) { ?>
                        <tr>
                            <td class="muted small nowrap"><?= View::e(View::date((string) $event->raw('created_at'))) ?></td>
                            <td><?= View::e((string) $event->raw('user_login')) ?></td>
                            <td>
                                <span class="badge <?= View::e(AuditEntry::badge((string) $event->raw('action'))) ?>"><?= View::e(AuditEntry::label((string) $event->raw('action'))) ?></span>
                                <?= View::e((string) $event->raw('description')) ?>
                            </td>
                        </tr>
                    <?php } ?>
                </table>
            </div>

            <p style="margin-top: 12px;"><a href="<?= View::e(View::route('admin.audit')) ?>">Весь журнал →</a></p>
        <?php } ?>
    </div>
<?php } ?>
