<?php

declare(strict_types=1);

/**
 * Вебхуки: подписки на события приложения.
 *
 * @var array{items: array<int, \Rsgrinko\Proton\Models\Webhook>, total: int, page: int, pages: int, per_page: int} $page
 * @var array<string, array<string, string>> $groups события по разделам
 * @var array<string, int> $stats посылки по состояниям
 * @var \Rsgrinko\Proton\Support\Filters $filters
 */

use Rsgrinko\Proton\Access\Permission;
use Rsgrinko\Proton\Models\IncomingHook;
use Rsgrinko\Proton\Models\Webhook;
use Rsgrinko\Proton\Models\WebhookDelivery;
use Rsgrinko\Proton\View\View;

$canManage = View::can(Permission::WEBHOOKS_MANAGE);
$events    = 0;

foreach ($groups as $group) {
    $events += count($group);
}
?>
<h1>Вебхуки</h1>

<div class="card">
    <p class="muted small">
        Вебхук получает событие приложения посылкой POST с подписью в заголовке
        <span class="mono">X-Proton-Signature</span>. Отправляет их воркер: упавшую посылку
        он повторит, а подписчик, молчащий слишком долго, отключится сам.
        Событий в реестре: <?= $events ?>.
    </p>

    <p class="muted small">
        Посылки: доставлено <?= (int) ($stats[WebhookDelivery::SENT] ?? 0) ?>,
        в очереди <?= (int) ($stats[WebhookDelivery::PENDING] ?? 0) ?>,
        не доставлено <?= (int) ($stats[WebhookDelivery::FAILED] ?? 0) ?>.
    </p>

    <?php if ($canManage) { ?>
        <div class="row">
            <a class="btn primary" href="<?= View::e(View::route('admin.webhooks.create')) ?>">Новый вебхук</a>
        </div>
    <?php } ?>
</div>

<?= View::partial('filters', ['filters' => $filters, 'route' => 'admin.webhooks', 'total' => $page['total']]) ?>

<div class="card">
    <h2>Список</h2>

    <?php if ($page['items'] === []) { ?>
        <p class="muted">Вебхуков пока нет.</p>
    <?php } else { ?>
        <div class="table-wrap">
            <table class="list">
                <tr class="head">
                    <th><?= View::partial('sort', ['filters' => $filters, 'route' => 'admin.webhooks', 'column' => 'name', 'label' => 'Название']) ?></th>
                    <th>Адрес</th>
                    <th class="hide-sm">События</th>
                    <th>Состояние</th>
                    <th class="hide-sm">Последняя посылка</th>
                    <?php if ($canManage) { ?><th></th><?php } ?>
                </tr>

                <?php foreach ($page['items'] as $webhook) { ?>
                    <tr>
                        <td>
                            <a href="<?= View::e(View::route('admin.webhooks.show', ['id' => $webhook->id()])) ?>">
                                <?= View::e((string) $webhook->name) ?>
                            </a>
                        </td>
                        <td class="mono small break"><?= View::e((string) $webhook->url) ?></td>
                        <td class="hide-sm muted small">
                            <?php if (in_array(Webhook::ALL_EVENTS, $webhook->events(), true)) { ?>
                                все
                            <?php } else { ?>
                                <?= count($webhook->events()) ?>
                            <?php } ?>
                        </td>
                        <td>
                            <?php if ((bool) $webhook->active) { ?>
                                <span class="badge ok">включён</span>
                            <?php } else { ?>
                                <span class="badge muted">отключён</span>
                            <?php } ?>
                        </td>
                        <td class="hide-sm muted small">
                            <?= View::e(View::ago((string) $webhook->raw('last_sent_at'))) ?>
                            <?php if ((int) $webhook->raw('last_status') > 0) { ?>
                                <span class="mono">(<?= (int) $webhook->raw('last_status') ?>)</span>
                            <?php } ?>
                        </td>
                        <?php if ($canManage) { ?>
                        <td class="right">
                            <div class="row end">
                                <form method="post" action="<?= View::e(View::route('admin.webhooks.test', ['id' => $webhook->id()])) ?>">
                                    <?= View::csrf() ?>
                                    <button type="submit">Проверить</button>
                                </form>
                            </div>
                        </td>
                        <?php } ?>
                    </tr>
                <?php } ?>
            </table>
        </div>

        <?= View::partial('pagination', [
            'route'  => 'admin.webhooks',
            'page'   => $page['page'],
            'pages'  => $page['pages'],
            'params' => $filters->params(),
        ]) ?>
    <?php } ?>
</div>

<div class="card">
    <div class="row">
        <h2 style="margin: 0;">Входящие посылки</h2>
        <span class="spacer"></span>
        <a class="btn small" href="<?= View::e(View::route('admin.webhooks.incoming')) ?>">Все входящие</a>
    </div>


    <p class="muted small">
        Источники объявляются в <span class="mono">config/incoming.php</span>, адрес —
        <span class="mono">POST или GET /api/v1/hooks/{источник}</span>. Ключа API не нужно:
        отправитель прикладывает токен источника. Объявлено источников: <?= count($sources) ?>.
    </p>

    <?php if ($incoming === []) { ?>
        <p class="muted small">Пока никто не присылал.</p>
    <?php } else { ?>
        <div class="table-wrap">
            <table class="list">
                <tr class="head">
                    <th>Когда</th>
                    <th>Источник</th>
                    <th>Событие</th>
                    <th>Состояние</th>
                    <th class="hide-sm">Адрес</th>
                </tr>

                <?php foreach ($incoming as $hook) { ?>
                    <tr>
                        <td class="muted small nowrap"><?= View::e(View::date((string) $hook->raw('created_at'))) ?></td>
                        <td class="small"><?= View::e((string) $hook->raw('source')) ?></td>
                        <td class="small"><?= View::e((string) $hook->raw('event') ?: '—') ?></td>
                        <td>
                            <span class="badge <?= $hook->raw('status') === IncomingHook::DONE ? 'ok' : 'error' ?>">
                                <?= View::e(IncomingHook::label((string) $hook->raw('status'))) ?>
                            </span>

                            <?php if (trim((string) $hook->raw('error')) !== '') { ?>
                                <div class="muted small"><?= View::e((string) $hook->raw('error')) ?></div>
                            <?php } ?>
                        </td>
                        <td class="hide-sm mono small"><?= View::e((string) $hook->raw('ip')) ?></td>
                    </tr>
                <?php } ?>
            </table>
        </div>
    <?php } ?>
</div>
