<?php

declare(strict_types=1);

/**
 * Карточка вебхука: адрес, события, секрет и журнал посылок.
 *
 * @var \Rsgrinko\Proton\Models\Webhook $webhook
 * @var array<string, array<string, string>> $groups события по разделам
 * @var array{items: array<int, \Rsgrinko\Proton\Models\WebhookDelivery>, total: int, page: int, pages: int, per_page: int} $deliveries
 * @var string $fresh только что выданный секрет — показывается один раз
 */

use Rsgrinko\Proton\Models\Webhook;
use Rsgrinko\Proton\Models\WebhookDelivery;
use Rsgrinko\Proton\View\View;

$isNew    = !$webhook->existsInDatabase();
$action   = $isNew ? View::route('admin.webhooks.create') : View::route('admin.webhooks.show', ['id' => $webhook->id()]);
$selected = $webhook->events();
$all      = in_array(Webhook::ALL_EVENTS, $selected, true);
?>
<h1><?= $isNew ? 'Новый вебхук' : View::e((string) $webhook->name) ?></h1>

<?php if ($fresh !== '') { ?>
    <div class="card" style="border-color: var(--accent);">
        <h2>Новый секрет</h2>
        <p class="muted small">Пропишите его у подписчика: старый больше не подойдёт.</p>
        <pre class="break"><?= View::e($fresh) ?></pre>
        <p><?= View::copy($fresh, 'скопировать секрет') ?></p>
    </div>
<?php } ?>

<div class="card">
    <form method="post" action="<?= View::e($action) ?>">
        <?= View::csrf() ?>

        <label>
            <span>Название</span>
            <input type="text" name="name" value="<?= View::e((string) $webhook->name) ?>" placeholder="склад 1С" <?= $isNew ? 'autofocus' : '' ?>>
        </label>

        <label>
            <span>Адрес подписчика</span>
            <input type="url" name="url" value="<?= View::e((string) $webhook->url) ?>" placeholder="https://example.com/hooks/proton" required>
        </label>

        <?php if ($isNew) { ?>
            <label>
                <span>Секрет подписи (пусто — придумаем сами)</span>
                <input type="text" name="secret" value="" autocomplete="off">
            </label>
        <?php } ?>

        <h2 style="margin-top: 16px;">События</h2>

        <label class="inline" style="margin-bottom: 6px;">
            <input type="checkbox" name="events[]" value="<?= View::e(Webhook::ALL_EVENTS) ?>" <?= $all ? 'checked' : '' ?>>
            <span>Все события — в том числе те, что появятся позже</span>
        </label>

        <?php foreach ($groups as $title => $events) { ?>
            <div class="card">
                <h2><?= View::e($title) ?></h2>

                <?php foreach ($events as $code => $label) { ?>
                    <label class="inline" style="margin-bottom: 6px;">
                        <input type="checkbox" name="events[]" value="<?= View::e($code) ?>"
                            <?= in_array($code, $selected, true) ? 'checked' : '' ?>>
                        <span><?= View::e($label) ?> <span class="muted mono"><?= View::e($code) ?></span></span>
                    </label>
                <?php } ?>
            </div>
        <?php } ?>

        <div class="row">
            <button type="submit" class="primary">Сохранить</button>
            <a class="btn" href="<?= View::e(View::route('admin.webhooks')) ?>">К списку</a>
        </div>
    </form>
</div>

<?php if (!$isNew) { ?>
    <div class="card">
        <h2>Секрет и состояние</h2>

        <table class="list">
            <tr>
                <th>Секрет</th>
                <td class="mono"><?= View::e($webhook->maskedSecret()) ?></td>
            </tr>
            <tr>
                <th>Состояние</th>
                <td>
                    <?php if ((bool) $webhook->active) { ?>
                        <span class="badge ok">включён</span>
                    <?php } else { ?>
                        <span class="badge muted">отключён</span>
                    <?php } ?>
                </td>
            </tr>
            <tr>
                <th>Последняя посылка</th>
                <td class="muted small">
                    <?= View::e(View::ago((string) $webhook->raw('last_sent_at'))) ?>
                    <?php if ((int) $webhook->raw('last_status') > 0) { ?>
                        <span class="mono">ответ <?= (int) $webhook->raw('last_status') ?></span>
                    <?php } ?>
                </td>
            </tr>
            <?php if ((string) $webhook->raw('last_error') !== '') { ?>
                <tr>
                    <th>Последняя ошибка</th>
                    <td class="small break"><?= View::e((string) $webhook->raw('last_error')) ?></td>
                </tr>
            <?php } ?>
            <tr>
                <th>Неудач подряд</th>
                <td><?= (int) $webhook->raw('failures') ?></td>
            </tr>
        </table>

        <div class="row" style="margin-top: 12px;">
            <form method="post" action="<?= View::e(View::route('admin.webhooks.test', ['id' => $webhook->id()])) ?>">
                <?= View::csrf() ?>
                <button type="submit" class="primary">Отправить пробную посылку</button>
            </form>

            <form method="post" action="<?= View::e(View::route('admin.webhooks.toggle', ['id' => $webhook->id()])) ?>">
                <?= View::csrf() ?>
                <button type="submit"><?= (bool) $webhook->active ? 'Отключить' : 'Включить' ?></button>
            </form>

            <form method="post" action="<?= View::e(View::route('admin.webhooks.rotate', ['id' => $webhook->id()])) ?>">
                <?= View::csrf() ?>
                <button type="submit">Заменить секрет</button>
            </form>

            <form method="post" action="<?= View::e(View::route('admin.webhooks.delete', ['id' => $webhook->id()])) ?>">
                <?= View::csrf() ?>
                <button type="submit" class="danger">Удалить вебхук</button>
            </form>
        </div>
    </div>

    <div class="card">
        <h2>Посылки</h2>

        <?php if ($deliveries['items'] === []) { ?>
            <p class="muted">Посылок пока не было.</p>
        <?php } else { ?>
            <div class="table-wrap">
                <table class="list">
                    <tr class="head">
                        <th>№</th>
                        <th>Событие</th>
                        <th>Состояние</th>
                        <th class="hide-sm">Ответ</th>
                        <th class="hide-sm">Попыток</th>
                        <th class="hide-sm">Время</th>
                        <th>Когда</th>
                    </tr>

                    <?php foreach ($deliveries['items'] as $delivery) { ?>
                        <tr>
                            <td class="mono"><?= $delivery->id() ?></td>
                            <td class="mono small"><?= View::e((string) $delivery->raw('event')) ?></td>
                            <td>
                                <?php if ((string) $delivery->raw('status') === WebhookDelivery::SENT) { ?>
                                    <span class="badge ok"><?= View::e($delivery->label()) ?></span>
                                <?php } elseif ((string) $delivery->raw('status') === WebhookDelivery::FAILED) { ?>
                                    <span class="badge error"><?= View::e($delivery->label()) ?></span>
                                <?php } else { ?>
                                    <span class="badge muted"><?= View::e($delivery->label()) ?></span>
                                <?php } ?>
                            </td>
                            <td class="hide-sm mono small">
                                <?= (int) $delivery->raw('response_status') > 0 ? (int) $delivery->raw('response_status') : '—' ?>
                                <?php if ((string) $delivery->raw('error') !== '') { ?>
                                    <div class="muted break"><?= View::e((string) $delivery->raw('error')) ?></div>
                                <?php } ?>
                            </td>
                            <td class="hide-sm"><?= (int) $delivery->raw('attempts') ?></td>
                            <td class="hide-sm muted small"><?= (int) $delivery->raw('duration_ms') ?> мс</td>
                            <td class="muted small"><?= View::e(View::date((string) $delivery->raw('created_at'))) ?></td>
                        </tr>
                    <?php } ?>
                </table>
            </div>

            <?= View::partial('pagination', [
                'route'  => 'admin.webhooks.show',
                'page'   => $deliveries['page'],
                'pages'  => $deliveries['pages'],
                'params' => ['id' => $webhook->id()],
            ]) ?>
        <?php } ?>
    </div>
<?php } ?>
