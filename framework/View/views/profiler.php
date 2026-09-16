<?php

declare(strict_types=1);

/**
 * Панель отладки внизу страницы: сколько запросов к базе, сколько времени,
 * что повторялось, какой маршрут и кто сейчас смотрит. Показывается только при
 * APP_DEBUG — на бою её нет вовсе.
 *
 * Повторы наверху нарочно: один и тот же SELECT двадцать раз — это N+1,
 * и заметить его надо раньше, чем он доедет до боя.
 *
 * Свёрнутая, панель занимает только строку `<summary>` — но она `position:
 * fixed`, поэтому место под неё каркас резервирует сам (`main.has-profiler`
 * в layouts/main.php), иначе она перекрывала бы последнюю строку таблицы
 * или кнопку внизу страницы.
 */

use Rsgrinko\Proton\Http\Router;
use Rsgrinko\Proton\Support\Profiler;
use Rsgrinko\Proton\Support\Str;
use Rsgrinko\Proton\View\View;

if (!Profiler::enabled()) {
    return;
}

$repeats = Profiler::repeats(3);
$slowest = Profiler::slowest(5);
$route   = Router::current();
$viewer  = View::viewer();
?>
<details class="profiler">
    <summary>
        база: <?= Profiler::count() ?> запр. / <?= View::e((string) Profiler::time()) ?> мс
        · страница: <?= View::e((string) Profiler::elapsed()) ?> мс
        · память: <?= View::e(Str::bytes(memory_get_peak_usage(true))) ?>
        <?php if ($route?->name !== null) { ?>
            · <?= View::e((string) $route->name) ?>
        <?php } ?>
        <?php if ($repeats !== []) { ?>
            · <span class="warn">повторов: <?= count($repeats) ?></span>
        <?php } ?>
    </summary>

    <h3>Запрос</h3>
    <dl class="props">
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
    </dl>

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
                    <td class="count"><?= View::e((string) $query['ms']) ?> мс</td>
                    <td class="count"><?= (int) $query['rows'] ?> стр.</td>
                    <td class="mono"><?= View::e(mb_substr($query['sql'], 0, 300)) ?></td>
                </tr>
            <?php } ?>
        </table>
    <?php } ?>

    <?php if (Profiler::count() > 0) { ?>
        <details>
            <summary>Все запросы по порядку (<?= Profiler::count() ?>)</summary>

            <table>
                <?php foreach (Profiler::queries() as $index => $query) { ?>
                    <tr>
                        <td class="count"><?= $index + 1 ?></td>
                        <td class="count"><?= View::e((string) $query['ms']) ?> мс</td>
                        <td class="count"><?= (int) $query['rows'] ?> стр.</td>
                        <td class="mono"><?= View::e(mb_substr($query['sql'], 0, 300)) ?></td>
                    </tr>
                <?php } ?>
            </table>
        </details>
    <?php } ?>
</details>
