<?php

declare(strict_types=1);

/**
 * Обзор панели.
 *
 * @var array{users: int, active: int, notes: int, queue: array<string, int>} $stats
 * @var string $health
 * @var array<int, \Rsgrinko\Proton\Models\AuditEntry> $events
 */

use Rsgrinko\Proton\Models\AuditEntry;
use Rsgrinko\Proton\View\View;

$badge = ['ok' => 'ok', 'warn' => 'warn', 'error' => 'error'][$health] ?? 'muted';
$label = ['ok' => 'всё в порядке', 'warn' => 'есть замечания', 'error' => 'есть проблемы'][$health] ?? $health;
?>
<h1>Панель</h1>

<div class="grid cols-4">
    <div class="card stat">
        <div class="label">Пользователей</div>
        <div class="value"><?= (int) $stats['users'] ?></div>
        <div class="muted small">активных: <?= (int) $stats['active'] ?></div>
    </div>

    <div class="card stat">
        <div class="label">Заметок</div>
        <div class="value"><?= (int) $stats['notes'] ?></div>
    </div>

    <div class="card stat">
        <div class="label">В очереди</div>
        <div class="value"><?= (int) ($stats['queue']['queued'] ?? 0) ?></div>
        <div class="muted small">с ошибкой: <?= (int) ($stats['queue']['failed'] ?? 0) ?></div>
    </div>

    <a class="card stat" href="<?= View::e(View::route('admin.system')) ?>">
        <div class="label">Состояние</div>
        <div class="value"><span class="badge <?= View::e($badge) ?>"><?= View::e($label) ?></span></div>
        <div class="muted small">подробности на странице состояния</div>
    </a>
</div>

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
                            <span class="badge muted"><?= View::e(AuditEntry::label((string) $event->raw('action'))) ?></span>
                            <?= View::e((string) $event->raw('description')) ?>
                        </td>
                    </tr>
                <?php } ?>
            </table>
        </div>

        <?php if (View::can('audit.view')) { ?>
            <p style="margin-top: 12px;"><a href="<?= View::e(View::route('admin.audit')) ?>">Весь журнал →</a></p>
        <?php } ?>
    <?php } ?>
</div>
