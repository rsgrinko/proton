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

    <details style="margin-bottom: 12px;">
        <summary class="small">как принять и обработать вебхук</summary>

        <p class="muted small">
            Вебхук — это способ рассказать вашему серверу о событии внутри Proton
            сразу, без опроса по расписанию. Когда что-то происходит (например,
            завели пользователя, удалили заказ и прочее), Proton сам делает обычный POST-запрос на адрес,
            который вы указали при заведении подписки, и кладёт в тело событие
            JSON-строкой. У вас на этом адресе лежит свой скрипт, который читает
            запрос и делает то, что нужно вашей системе.
        </p>

        <p class="small"><strong>Что приходит в запросе</strong></p>
        <p class="muted small">
            Заголовки — служебная информация о посылке, тело — само событие:
        </p>
        <pre class="break">POST /ваш/адрес HTTP/1.1
Content-Type: application/json
X-Proton-Event: note.created
X-Proton-Delivery: 142
X-Proton-Timestamp: 1789248523
X-Proton-Signature: sha256=d17eb4a7df26140c84da555675a8ed5d…

{
  "event": "note.created",
  "timestamp": 1789248522,
  "sent_at": "2026-09-13 00:28:42",
  "app": "Proton",
  "data": { "note": { "id": 5, "title": "Заметка", "user_id": 3 } }
}</pre>
        <p class="muted small">
            <span class="mono">X-Proton-Event</span> дублирует поле
            <span class="mono">event</span> в теле — удобно, если нужно понять
            тип события, не разбирая JSON. <span class="mono">X-Proton-Delivery</span> —
            порядковый номер посылки: по нему узнаётся повтор (см. ниже).
            <span class="mono">X-Proton-Timestamp</span> — unix-время отправки, участвует
            в подписи. <span class="mono">X-Proton-Signature</span> — сама подпись,
            разбор — следующим пунктом.
        </p>

        <p class="small"><strong>Секрет и подпись</strong></p>
        <p class="muted small">
            Секрет знают только Proton и вы. На его основании каждая сторона у
            себя считает подпись для избежания фальсификации запроса.
            Секрет показывается один раз открытым текстом сразу после создания подписки или после
            «Сменить секрет», дальше — только маска вида
            <span class="mono">секрет12••••••••</span>. Если секрет утрачен или скомпрометирован — нажмите «Сменить
            секрет». Старый перестанет работать сразу же.
        </p>
        <p class="muted small">
            Подпись — это HMAC-SHA256 от строки «время, точка, тело запроса»,
            посчитанный с этим секретом. Сверить её у себя:
        </p>
        <pre class="break">$secret = 'секрет_из_панели'; // тот самый, что показали один раз

$timestamp = $_SERVER['HTTP_X_PROTON_TIMESTAMP'] ?? '';
$signature = $_SERVER['HTTP_X_PROTON_SIGNATURE'] ?? '';
$body      = file_get_contents('php://input'); // тело как оно есть, до json_decode

$expected = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);

// используйте hash_equals, так как обычное сравнение строк выдаёт секрет по времени ответа
if (!hash_equals($expected, $signature)) {
    http_response_code(403);
    exit;
}</pre>
        <p class="muted small">
            Стоит добавить и проверку времени: подпись без неё можно было бы
            подсмотреть и отправить от вашего имени ещё раз хоть через год.
        </p>
        <pre class="break">if (abs(time() - (int) $timestamp) > 300) { // пять минут — разумный запас
    http_response_code(403);
    exit;
}</pre>

        <p class="small"><strong>Пример целиком</strong></p>
        <p class="muted small">
            Рабочий обработчик от начала до конца — без фреймворка, только сам
            PHP. Положите на адрес, который укажете в подписке:
        </p>
        <pre class="break">&lt;?php

$secret = 'секрет_из_панели';

$timestamp = $_SERVER['HTTP_X_PROTON_TIMESTAMP'] ?? '';
$signature = $_SERVER['HTTP_X_PROTON_SIGNATURE'] ?? '';
$body      = file_get_contents('php://input');

$expected = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);

if (!hash_equals($expected, $signature) || abs(time() - (int) $timestamp) > 300) {
    http_response_code(403);
    exit;
}

// Одна и та же посылка иногда приходит дважды — если в прошлый раз вы не
// ответили вовремя. Отличить повтор от новой посылки можно по номеру:
$delivery = $_SERVER['HTTP_X_PROTON_DELIVERY'] ?? '';
// например: если номер уже есть в своей базе — просто ответить 200 и выйти

$payload = json_decode($body, true);
$event   = $payload['event'] ?? '';
$data    = $payload['data'] ?? [];

if ($event === 'note.created') {
    // здесь ваша логика: записать к себе в базу, дёрнуть 1С, отправить письмо
}

http_response_code(200); // что угодно 2xx — Proton считает посылку доставленной</pre>
        <p class="muted small">
            Всё, что не 2xx (включая отсутствие ответа за
            <span class="mono">WEBHOOKS_TIMEOUT</span> секунд), Proton считает
            неудачей и повторит с растущей паузой — 30 секунд, минута, две и
            так далее, всего <span class="mono">WEBHOOKS_ATTEMPTS</span> раз.
            Если подписка так и не ответила подряд
            <span class="mono">WEBHOOKS_DISABLE_AFTER</span> раз — она отключается
            сама, и тем, у кого есть право <span class="mono">webhooks.manage</span>,
            приходит уведомление: возить посылки в никуда незачем.
        </p>

        <p class="small"><strong>Как проверить, что всё работает</strong></p>
        <p class="muted small">
            Кнопка «Проверить» у подписки в списке (или «Отправить пробную
            посылку» на её карточке) шлёт синтетическое событие
            <span class="mono">webhook.test</span> прямо сейчас — не нужно ждать
            реального действия в приложении, чтобы проверить связку целиком.
            Результат виден сразу в двух местах: строка в «Посылках» на карточке
            вебхука (код ответа, сколько шло, ошибка, если была) и то, что
            реально получил ваш обработчик — если своего адреса под рукой ещё
            нет, временно подставьте что-то вроде webhook.site или requestbin,
            они покажут заголовки и тело без единой строчки кода.
        </p>

        <p class="small"><strong>Если что-то не сходится</strong></p>
        <p class="muted small">
            Код ответа 403 у вас же и означает, что не сошлась подпись — чаще
            всего секрет успели сменить в панели, а у себя забыли обновить,
            либо тело перед сравнением случайно поменяли (лишний пробел, другой
            перенос строки: подписывается запрос ровно как он пришёл, до
            <span class="mono">json_decode</span>). Таймаут или «нет ответа» —
            обработчик недоступен с сервера Proton или отвечает дольше
            <span class="mono">WEBHOOKS_TIMEOUT</span> секунд — проверяйте
            доступность именно с сервера, а не со своего компьютера. Приходит
            событие, на которое вы не подписывались, — скорее всего включена
            галочка «Все события»: она берёт и то, что появится в реестре
            позже, не только нынешний список. Пробная посылка не приходит
            вовсе — подписка могла отключиться сама (см. выше) или не запущен
            воркер: тогда она висит в очереди, а не помечена «не доставлена».
            Подробнее и примеры не на PHP — <span class="mono">docs/WEBHOOKS.md</span>.
        </p>
    </details>

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
