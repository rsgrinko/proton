<?php

declare(strict_types=1);

/**
 * Подписки на события.
 *
 * @var array{items: array<int, \Rsgrinko\Proton\Models\Webhook>, total: int, page: int, pages: int, per_page: int} $page
 * @var array<string, array<string, string>> $groups события по разделам
 * @var array<string, int> $stats посылки по состояниям
 */

use Rsgrinko\Proton\Models\Webhook;
use Rsgrinko\Proton\Models\WebhookDelivery;
use Rsgrinko\Proton\View\View;

$events = 0;

foreach ($groups as $group) {
    $events += count($group);
}
?>
<h1>Вебхуки</h1>

<div class="card">
    <p class="muted small">
        Подписка получает событие приложения посылкой POST с подписью в заголовке
        <span class="mono">X-Proton-Signature</span>. Отправляет их воркер: упавшую посылку
        он повторит, а подписчик, молчащий слишком долго, отключится сам.
        Событий в реестре: <?= $events ?>.
    </p>

    <p class="muted small">
        Посылки: доставлено <?= (int) ($stats[WebhookDelivery::SENT] ?? 0) ?>,
        в очереди <?= (int) ($stats[WebhookDelivery::PENDING] ?? 0) ?>,
        не доставлено <?= (int) ($stats[WebhookDelivery::FAILED] ?? 0) ?>.
    </p>

    <div class="row">
        <a class="btn primary" href="<?= View::e(View::route('admin.webhooks.create')) ?>">Новая подписка</a>
    </div>
</div>

<div class="card">
    <h2>Подписки</h2>

    <?php if ($page['items'] === []) { ?>
        <p class="muted">Подписок пока нет.</p>
    <?php } else { ?>
        <div class="table-wrap">
            <table class="list">
                <tr class="head">
                    <th>Название</th>
                    <th>Адрес</th>
                    <th class="hide-sm">События</th>
                    <th>Состояние</th>
                    <th class="hide-sm">Последняя посылка</th>
                    <th></th>
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
                                <span class="badge ok">включена</span>
                            <?php } else { ?>
                                <span class="badge muted">отключена</span>
                            <?php } ?>
                        </td>
                        <td class="hide-sm muted small">
                            <?= View::e(View::ago((string) $webhook->raw('last_sent_at'))) ?>
                            <?php if ((int) $webhook->raw('last_status') > 0) { ?>
                                <span class="mono">(<?= (int) $webhook->raw('last_status') ?>)</span>
                            <?php } ?>
                        </td>
                        <td class="right">
                            <div class="row end">
                                <form method="post" action="<?= View::e(View::route('admin.webhooks.test', ['id' => $webhook->id()])) ?>">
                                    <?= View::csrf() ?>
                                    <button type="submit">Проверить</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php } ?>
            </table>
        </div>

        <?= View::partial('pagination', [
            'route'  => 'admin.webhooks',
            'page'   => $page['page'],
            'pages'  => $page['pages'],
            'params' => [],
        ]) ?>
    <?php } ?>
</div>
