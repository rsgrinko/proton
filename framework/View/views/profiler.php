<?php

declare(strict_types=1);

/**
 * Панель отладки внизу страницы: запрос, выполнение по слоям, SQL с таймлайном
 * и кэш — всё, что случилось за этот запрос. Показывается только при
 * APP_DEBUG — на бою её нет вовсе.
 *
 * Вкладки — на радиокнопках, без JavaScript: страница остаётся рабочей
 * даже если скрипты не выполнились. Свёрнутая, панель занимает только
 * строку `<summary>` — но она `position: fixed`, поэтому место под неё
 * каркас резервирует сам (`main.has-profiler` в layouts/main.php), иначе
 * она перекрывала бы последнюю строку таблицы или кнопку внизу страницы.
 */

use Rsgrinko\Proton\Http\Request;
use Rsgrinko\Proton\Http\Router;
use Rsgrinko\Proton\Support\Profiler;
use Rsgrinko\Proton\Support\RequestId;
use Rsgrinko\Proton\Support\Str;
use Rsgrinko\Proton\View\View;

if (!Profiler::enabled()) {
    return;
}

$repeats = Profiler::repeats(3);
$slowest = Profiler::slowest(5);
$queries = Profiler::queries();
$route   = Router::current();
$request = Request::current();
$viewer  = View::viewer();
$elapsed = Profiler::elapsed();
$slowMs  = Profiler::slowThreshold();
$cache   = Profiler::cacheStats();
$marks   = Profiler::marks();

/**
 * Отметки «вход»/«выход» сворачиваются в слои: у каждого — начало, конец
 * и глубина вложенности. Стрелками вложенность не показать наглядно, а
 * отступом и полосой на общей шкале — видно сразу, как во вкладке SQL.
 *
 * @var array<int, array{label: string, location: string, start: float, end: ?float, depth: int}> $layers
 */
$layers = [];
$open   = [];

foreach ($marks as $mark) {
    if (str_ends_with($mark['label'], ' →')) {
        $layers[] = [
            'label'    => mb_substr($mark['label'], 0, -2),
            'location' => $mark['location'],
            'start'    => $mark['offset'],
            'end'      => null,
            'depth'    => count($open),
        ];
        $open[] = array_key_last($layers);

        continue;
    }

    if (str_ends_with($mark['label'], ' ←') && $open !== []) {
        $layers[array_pop($open)]['end'] = $mark['offset'];

        continue;
    }

    // Одиночная отметка без пары («kernel: старт») — точка, а не слой
    $layers[] = [
        'label'    => $mark['label'],
        'location' => $mark['location'],
        'start'    => $mark['offset'],
        'end'      => $mark['offset'],
        'depth'    => count($open),
    ];
}

/**
 * Класс бейджа/полосы по продолжительности запроса — тот же порог, что
 * пишет медленный запрос в лог, чтобы «жёлтое» в панели и предупреждение
 * в логе не расходились.
 */
$tier = static function (float $ms) use ($slowMs): string {
    if ($slowMs > 0 && $ms >= $slowMs) {
        return 'error';
    }

    if ($slowMs > 0 && $ms >= $slowMs / 2) {
        return 'warn';
    }

    return 'ok';
};

$typeBadge = static fn (string $type): string => match ($type) {
    'SELECT' => 'info',
    'INSERT' => 'ok',
    'UPDATE' => 'warn',
    'DELETE' => 'error',
    default  => 'muted',
};

// Метки вкладок красятся сами, когда внутри есть на что обратить внимание —
// не нужно разворачивать все вкладки подряд, чтобы понять, где смотреть первым
$sqlWarn   = $repeats !== [] || ($slowest !== [] && $slowest[0]['ms'] >= $slowMs);
$cacheWarn = $cache['misses'] > 0;
?>
<details class="profiler">
    <summary>
        база: <?= Profiler::count() ?> запр. / <?= View::e((string) Profiler::time()) ?> мс
        · страница: <?= View::e((string) $elapsed) ?> мс
        · память: <?= View::e(Str::bytes(memory_get_peak_usage(true))) ?>
        <?php if ($route?->name !== null) { ?>
            · <?= View::e((string) $route->name) ?>
        <?php } ?>
        <?php if ($repeats !== []) { ?>
            · <span class="warn">повторов: <?= count($repeats) ?></span>
        <?php } ?>
        <?php if ($cache['hits'] + $cache['misses'] > 0) { ?>
            · кэш: <?= $cache['hits'] ?>/<?= $cache['hits'] + $cache['misses'] ?>
        <?php } ?>
    </summary>

    <div class="profiler-tabs">
        <input type="radio" name="profiler-tab" id="profiler-tab-request" checked>
        <input type="radio" name="profiler-tab" id="profiler-tab-run">
        <input type="radio" name="profiler-tab" id="profiler-tab-sql">
        <input type="radio" name="profiler-tab" id="profiler-tab-cache">

        <div class="profiler-tab-heads">
            <label for="profiler-tab-request">Запрос</label>
            <label for="profiler-tab-run">Выполнение (<?= count($layers) ?>)</label>
            <label for="profiler-tab-sql" class="<?= $sqlWarn ? 'has-warn' : '' ?>">SQL (<?= Profiler::count() ?>)</label>
            <label for="profiler-tab-cache" class="<?= $cacheWarn ? 'has-warn' : '' ?>">Кэш (<?= count(Profiler::cacheEntries()) ?>)</label>
        </div>

        <div class="profiler-tab-panels">
            <section class="profiler-panel panel-request">
                <dl class="props">
                    <?php if ($request !== null) { ?>
                        <dt>Метод и путь</dt>
                        <dd>
                            <span class="badge info"><?= View::e($request->method) ?></span> <?= View::e($request->path) ?>
                            <?php if ($request->query !== []) { ?>
                                <span class="muted">— параметры адреса: <?= View::e(implode(', ', array_keys($request->query))) ?></span>
                            <?php } ?>
                        </dd>
                    <?php } ?>

                    <dt>Маршрут</dt>
                    <dd><?= $route !== null ? View::e((string) ($route->name ?? '(без имени)') . ' — ' . $route->pattern) : '—' ?></dd>

                    <dt>Прослойки</dt>
                    <dd><?= $route !== null && $route->middleware !== [] ? View::e(implode(', ', $route->middleware)) : '—' ?></dd>

                    <dt>Кто смотрит</dt>
                    <dd>
                        <?php if ($viewer->isGuest()) { ?>
                            гость
                        <?php } else { ?>
                            <?= View::e($viewer->name()) ?> (id <?= $viewer->id() ?>)
                            <?php if (View::isImpersonating()) { ?>
                                — вместо <?= View::e((string) View::impersonator()?->login) ?>
                            <?php } ?>
                        <?php } ?>
                    </dd>

                    <?php if ($request !== null) { ?>
                        <dt>Адрес</dt>
                        <dd class="mono"><?= View::e($request->ip()) ?></dd>

                        <dt>Ждёт в ответ</dt>
                        <dd><?= $request->wantsJson() ? 'JSON' : 'HTML' ?></dd>

                        <dt>Content-Type</dt>
                        <dd><?= $request->header('content-type') !== '' ? View::e($request->header('content-type')) : '—' ?></dd>

                        <dt>Request-Id</dt>
                        <dd class="mono"><?= View::e(RequestId::current()) ?></dd>

                        <dt>User-Agent</dt>
                        <dd><?= $request->userAgent() !== '' ? View::e($request->userAgent()) : '—' ?></dd>

                        <?php $fields = array_keys($request->all()); ?>
                        <dt>Поля запроса</dt>
                        <dd>
                            <?php if ($fields !== []) { ?>
                                <?= View::e(implode(', ', $fields)) ?>
                                <span class="muted">— имена, без значений</span>
                            <?php } else { ?>
                                —
                            <?php } ?>
                        </dd>

                        <?php if ($request->files !== []) { ?>
                            <dt>Файлы</dt>
                            <dd><?= View::e(implode(', ', array_keys($request->files))) ?></dd>
                        <?php } ?>
                    <?php } ?>
                </dl>
            </section>

            <section class="profiler-panel panel-run">
                <?php if ($layers !== []) { ?>
                    <p class="muted">
                        Каждая строка — прослойка или контроллер. Отступ и положение внутри
                        родительской полосы показывают вложенность, ширина полосы — сколько
                        занял сам слой вместе со всем, что внутри него.
                    </p>

                    <table class="profiler-timeline">
                        <tr class="timeline-scale-row">
                            <td></td>
                            <td></td>
                            <td class="timeline-cell timeline-scale">
                                <?php foreach ([0, 0.25, 0.5, 0.75, 1] as $point) {
                                    $class = $point === 0 ? 'edge-start' : ($point === 1 ? 'edge-end' : 'mid');
                                    $style = $point === 0 ? 'left: 0' : ($point === 1 ? 'right: 0' : 'left: ' . ($point * 100) . '%');
                                ?>
                                    <span class="<?= $class ?>" style="<?= $style ?>">
                                        <?= View::e((string) round($elapsed * $point)) ?> мс
                                    </span>
                                <?php } ?>
                            </td>
                        </tr>
                        <?php foreach ($layers as $layer) {
                            $end      = $layer['end'] ?? $elapsed;
                            $duration = max(0.0, $end - $layer['start']);
                            $left     = $elapsed > 0 ? min(100.0, max(0.0, $layer['start'] / $elapsed * 100)) : 0.0;
                            $width    = $elapsed > 0 ? min(100.0 - $left, max(0.6, $duration / $elapsed * 100)) : 0.0;
                            $row      = $tier($duration);
                        ?>
                            <tr>
                                <td class="count"><?= View::e((string) round($duration, 2)) ?> мс</td>
                                <td class="mono" style="padding-left: <?= 6 + $layer['depth'] * 14 ?>px">
                                    <?= View::e($layer['label']) ?>
                                    <?php if ($layer['location'] !== '') { ?>
                                        <br><span class="muted"><?= View::e($layer['location']) ?></span>
                                    <?php } ?>
                                </td>
                                <td class="timeline-cell">
                                    <span class="timeline-bar bar-<?= $row ?>" style="left: <?= round($left, 2) ?>%; width: <?= round($width, 2) ?>%;"></span>
                                </td>
                            </tr>
                        <?php } ?>
                    </table>
                <?php } else { ?>
                    <p class="muted">Отметок нет — запрос не дошёл до маршрутов (например, режим обслуживания).</p>
                <?php } ?>
            </section>

            <section class="profiler-panel panel-sql">
                <?php if ($queries !== []) { ?>
                    <h3>Таймлайн</h3>

                    <table class="profiler-timeline">
                        <tr class="timeline-scale-row">
                            <td></td>
                            <td></td>
                            <td></td>
                            <td class="timeline-cell timeline-scale">
                                <?php foreach ([0, 0.25, 0.5, 0.75, 1] as $point) {
                                    $class = $point === 0 ? 'edge-start' : ($point === 1 ? 'edge-end' : 'mid');
                                    $style = $point === 0 ? 'left: 0' : ($point === 1 ? 'right: 0' : 'left: ' . ($point * 100) . '%');
                                ?>
                                    <span class="<?= $class ?>" style="<?= $style ?>">
                                        <?= View::e((string) round($elapsed * $point)) ?> мс
                                    </span>
                                <?php } ?>
                            </td>
                        </tr>
                        <?php foreach ($queries as $index => $query) {
                            $left  = $elapsed > 0 ? min(100.0, max(0.0, $query['offset'] / $elapsed * 100)) : 0.0;
                            $width = $elapsed > 0 ? min(100.0 - $left, max(0.6, $query['ms'] / $elapsed * 100)) : 0.0;
                            $row   = $tier($query['ms']);
                        ?>
                            <tr>
                                <td class="count"><?= $index + 1 ?></td>
                                <td><span class="badge <?= $typeBadge($query['type']) ?>"><?= View::e($query['type']) ?></span></td>
                                <td class="count"><span class="badge <?= $row ?>"><?= View::e((string) $query['ms']) ?> мс</span></td>
                                <td class="timeline-cell">
                                    <span class="timeline-bar bar-<?= $row ?>" style="left: <?= round($left, 2) ?>%; width: <?= round($width, 2) ?>%;"></span>
                                </td>
                            </tr>
                        <?php } ?>
                    </table>
                <?php } ?>

                <?php if ($repeats !== []) { ?>
                    <h3>Повторяющиеся запросы</h3>

                    <table>
                        <?php foreach ($repeats as $sql => $count) { ?>
                            <tr>
                                <td class="count"><?= (int) $count ?>×</td>
                                <td class="mono"><?= View::e(mb_substr((string) $sql, 0, 300)) ?></td>
                            </tr>
                        <?php } ?>
                    </table>
                <?php } ?>

                <?php if ($slowest !== []) { ?>
                    <h3>Самые долгие</h3>

                    <table>
                        <?php foreach ($slowest as $query) { ?>
                            <tr>
                                <td class="count"><span class="badge <?= $tier($query['ms']) ?>"><?= View::e((string) $query['ms']) ?> мс</span></td>
                                <td class="count"><?= (int) $query['rows'] ?> стр.</td>
                                <td class="mono">
                                    <?= View::e(mb_substr($query['sql'], 0, 300)) ?>

                                    <?php if ($query['trace'] !== []) { ?>
                                        <details class="trace">
                                            <summary>стек</summary>
                                            <ol>
                                                <?php foreach ($query['trace'] as $frame) { ?>
                                                    <li>
                                                        <?= View::e($frame['file']) ?>:<?= (int) $frame['line'] ?>
                                                        <?php if ($frame['call'] !== '') { ?>
                                                            — <?= View::e($frame['call']) ?>()
                                                        <?php } ?>
                                                    </li>
                                                <?php } ?>
                                            </ol>
                                        </details>
                                    <?php } ?>
                                </td>
                            </tr>
                        <?php } ?>
                    </table>
                <?php } ?>

                <?php if ($queries !== []) { ?>
                    <details>
                        <summary>Все запросы по порядку (<?= count($queries) ?>)</summary>

                        <table>
                            <?php foreach ($queries as $index => $query) { ?>
                                <tr>
                                    <td class="count"><?= $index + 1 ?></td>
                                    <td><span class="badge <?= $typeBadge($query['type']) ?>"><?= View::e($query['type']) ?></span></td>
                                    <td class="count"><span class="badge <?= $tier($query['ms']) ?>"><?= View::e((string) $query['ms']) ?> мс</span></td>
                                    <td class="count"><?= (int) $query['rows'] ?> стр.</td>
                                    <td class="mono">
                                        <?= View::e(mb_substr($query['sql'], 0, 300)) ?>
                                        <?= View::copy($query['sql'], 'SQL') ?>

                                        <?php if ($query['trace'] !== []) { ?>
                                            <details class="trace">
                                                <summary>стек</summary>
                                                <ol>
                                                    <?php foreach ($query['trace'] as $frame) { ?>
                                                        <li>
                                                            <?= View::e($frame['file']) ?>:<?= (int) $frame['line'] ?>
                                                            <?php if ($frame['call'] !== '') { ?>
                                                                — <?= View::e($frame['call']) ?>()
                                                            <?php } ?>
                                                        </li>
                                                    <?php } ?>
                                                </ol>
                                            </details>
                                        <?php } ?>
                                    </td>
                                </tr>
                            <?php } ?>
                        </table>
                    </details>
                <?php } else { ?>
                    <p class="muted">Запросов не было.</p>
                <?php } ?>
            </section>

            <section class="profiler-panel panel-cache">
                <p>
                    попаданий: <span class="badge ok"><?= $cache['hits'] ?></span>
                    · промахов: <span class="badge <?= $cache['misses'] > 0 ? 'warn' : 'muted' ?>"><?= $cache['misses'] ?></span>
                </p>

                <?php if (Profiler::cacheEntries() !== []) { ?>
                    <table>
                        <?php foreach (Profiler::cacheEntries() as $entry) { ?>
                            <tr>
                                <td class="count">
                                    <span class="badge <?= $entry['hit'] ? 'ok' : 'muted' ?>"><?= $entry['hit'] ? 'есть' : 'нет' ?></span>
                                </td>
                                <td class="mono"><?= View::e($entry['key']) ?></td>
                            </tr>
                        <?php } ?>
                    </table>
                <?php } else { ?>
                    <p class="muted">К кэшу не обращались.</p>
                <?php } ?>
            </section>
        </div>
    </div>
</details>
