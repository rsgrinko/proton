<?php

declare(strict_types=1);

/**
 * Входящие вебхуки: кого слушаем, что присылали и с каким телом.
 *
 * @var array{items: array<int, \Rsgrinko\Proton\Models\IncomingHook>, total: int, page: int, pages: int, per_page: int} $page
 * @var \Rsgrinko\Proton\Support\Filters $filters
 * @var array<string, array{label: string, token: string, handler: callable}> $sources
 */

use Rsgrinko\Proton\Models\IncomingHook;
use Rsgrinko\Proton\Support\Config;
use Rsgrinko\Proton\View\View;

$base = rtrim((string) Config::get('app.url', ''), '/');

/**
 * Тело посылки человеку: в базе оно лежит как есть, на экране — с отступами.
 */
$pretty = static function (mixed $payload): string {
    if ($payload === null || $payload === []) {
        return '—';
    }

    return (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
};
?>
<div class="row">
    <h1 style="margin: 0;">Входящие вебхуки</h1>
    <span class="spacer"></span>
    <a class="btn" href="<?= View::e(View::route('admin.webhooks')) ?>">← к вебхукам</a>
</div>

<div class="card" style="margin-top: 16px;">
    <h2>Как нам присылать</h2>

    <p class="muted small">
        Отправитель делает запрос на адрес источника и прикладывает токен. Метод любой:
        POST с телом JSON или GET с параметрами — обработчик получит одно и то же.
    </p>

    <p class="muted small">
        Токен можно передать тремя способами, как умеет отправляющая система:
        заголовком <span class="mono">X-Proton-Token: &lt;токен&gt;</span>, заголовком
        <span class="mono">Authorization: Bearer &lt;токен&gt;</span> или параметром
        <span class="mono">token=&lt;токен&gt;</span> в адресе или теле. Сам токен лежит
        в <span class="mono">.env</span> на сервере — в панели видно только имя переменной.
    </p>

    <p class="muted small">
        Поле <span class="mono">event</span> (или <span class="mono">type</span>) попадает
        в журнал отдельной колонкой — по нему потом удобно искать. Остальное разбирает
        обработчик источника.
    </p>

    <h3 class="small">POST с телом JSON</h3>

    <pre class="break small">curl -X POST <?= View::e($base) ?>/api/v1/hooks/billing \
     -H 'Content-Type: application/json' \
     -H 'X-Proton-Token: ВАШ_ТОКЕН' \
     -d '{
       "event": "order.paid",
       "id": 10231,
       "amount": 4500.00,
       "currency": "RUB",
       "customer": {"email": "ivan@example.com", "name": "Иван"}
     }'</pre>

    <h3 class="small">GET с параметрами</h3>

    <pre class="break small">curl "<?= View::e($base) ?>/api/v1/hooks/billing?event=order.paid&id=10231&token=ВАШ_ТОКЕН"</pre>

    <p class="muted small">
        У GET-посылки тела нет — параметры адреса и есть её тело. Токен в адресе оседает
        в логах прокси и браузера, поэтому заголовок надёжнее; параметр оставлен для
        систем, которые заголовки слать не умеют.
    </p>

    <h3 class="small">Как завести свой источник</h3>

    <p class="muted small">
        Источник описывается в <span class="mono">config/incoming.php</span>: ключ
        (он же кусок адреса), название, имя переменной с токеном в <span class="mono">.env</span> (пусто —
        принимать без проверки) и обработчик. Обработчик должен отвечать быстро:
        всё долгое отдаётся очереди.
    </p>

    <pre class="break small">Incoming::register('billing', 'Биллинг', 'BILLING_HOOK_TOKEN', static function (array $payload): void {
    if ((string) ($payload['event'] ?? '') !== 'order.paid') {
        return;
    }

    Queue::push(MarkOrderPaidJob::class, ['order' => (int) $payload['id']]);
});</pre>

    <p class="muted small">
        Ответы: <span class="mono">200</span> — приняли и обработали,
        <span class="mono">202</span> — приняли, но обработчик упал (повторять не нужно,
        разберёмся по журналу), <span class="mono">401</span> — токен не подошёл,
        <span class="mono">404</span> — такого источника нет.
    </p>

    <p class="muted small">
        Ответ <span class="mono">401</span> при заведомо верном токене означает, что на
        сервере пуста сама переменная: источник с объявленной, но пустой переменной не
        принимает никого — это недонастройка, а не разрешение пускать всех. Имя переменной
        видно в таблице источников, значение кладут в <span class="mono">.env</span>.
    </p>
</div>

<div class="card">
    <h2>Источники</h2>

    <p class="muted small">
        «Проверить» шлёт себе пробную посылку тем же путём, что настоящая система:
        с токеном из <span class="mono">.env</span> сервера (в панель он не уходит) и
        событием <span class="mono">incoming.test</span>. Результат виден сразу — строкой
        в посылках ниже, без очереди и ожидания.
    </p>

    <?php if ($sources === []) { ?>
        <p class="muted small">Ни одного источника не объявлено — см. <span class="mono">config/incoming.php</span>.</p>
    <?php } else { ?>
        <div class="table-wrap">
            <table class="list">
                <tr class="head">
                    <th>Ключ</th>
                    <th>Название</th>
                    <th>Адрес приёма</th>
                    <th>Токен</th>
                    <th></th>
                </tr>

                <?php foreach ($sources as $key => $source) { ?>
                    <tr>
                        <td class="mono small"><?= View::e((string) $key) ?></td>
                        <td><?= View::e($source['label']) ?></td>
                        <td class="mono small break">POST или GET <?= View::e($base) ?>/api/v1/hooks/<?= View::e((string) $key) ?></td>
                        <td class="small">
                            <?php if ($source['token'] === '') { ?>
                                <span class="badge warn">без проверки</span>
                                <div class="muted small">принимаем всех, кто знает адрес</div>
                            <?php } else { ?>
                                <span class="badge ok">по токену</span>
                                <div class="muted small">значение в <span class="mono">.env</span>, переменная <span class="mono"><?= View::e($source['token']) ?></span></div>
                            <?php } ?>
                        </td>
                        <td class="right">
                            <form method="post" action="<?= View::e(View::route('admin.webhooks.incoming.test')) ?>">
                                <?= View::csrf() ?>
                                <input type="hidden" name="source" value="<?= View::e((string) $key) ?>">
                                <button type="submit">Проверить</button>
                            </form>
                        </td>
                    </tr>
                <?php } ?>
            </table>
        </div>
    <?php } ?>
</div>

<?= View::partial('filters', ['filters' => $filters, 'route' => 'admin.webhooks.incoming', 'total' => $page['total']]) ?>

<div class="card">
    <h2>Посылки</h2>

    <?php if ($page['items'] === []) { ?>
        <p class="muted">Ничего не нашлось.</p>
    <?php } else { ?>
        <div class="table-wrap">
            <table class="list">
                <tr class="head">
                    <th><?= View::partial('sort', ['filters' => $filters, 'route' => 'admin.webhooks.incoming', 'column' => 'created_at', 'label' => 'Когда']) ?></th>
                    <th>Источник</th>
                    <th>Событие</th>
                    <th>Состояние</th>
                    <th class="hide-sm">Адрес</th>
                </tr>

                <?php foreach ($page['items'] as $hook) { ?>
                    <tr>
                        <td class="muted small nowrap"><?= View::e(View::date((string) $hook->raw('created_at'))) ?></td>
                        <td class="small"><?= View::e((string) $hook->raw('source')) ?></td>
                        <td class="small"><?= View::e((string) $hook->raw('event') ?: '—') ?></td>
                        <td>
                            <span class="badge <?= $hook->raw('status') === IncomingHook::DONE ? 'ok' : 'error' ?>">
                                <?= View::e(IncomingHook::label((string) $hook->raw('status'))) ?>
                            </span>

                            <?php if (trim((string) $hook->raw('error')) !== '') { ?>
                                <div class="muted small break"><?= View::e((string) $hook->raw('error')) ?></div>
                            <?php } ?>
                        </td>
                        <td class="hide-sm mono small"><?= View::e((string) $hook->raw('ip')) ?></td>
                    </tr>

                    <tr>
                        <td colspan="5" style="padding-top: 0;">
                            <details class="log-context">
                                <summary class="small">тело запроса</summary>
                                <pre class="break small"><?= View::e($pretty($hook->payload)) ?></pre>
                            </details>
                        </td>
                    </tr>
                <?php } ?>
            </table>
        </div>

        <?= View::partial('pagination', [
            'route'  => 'admin.webhooks.incoming',
            'page'   => $page['page'],
            'pages'  => $page['pages'],
            'params' => $filters->params(),
        ]) ?>
    <?php } ?>
</div>
