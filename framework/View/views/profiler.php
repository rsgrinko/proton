<?php

declare(strict_types=1);

/**
 * Панель отладки внизу страницы: сколько запросов к базе, сколько времени,
 * что повторялось. Показывается только при APP_DEBUG — на бою её нет вовсе.
 *
 * Повторы наверху нарочно: один и тот же SELECT двадцать раз — это N+1,
 * и заметить его надо раньше, чем он доедет до боя.
 */

use Rsgrinko\Proton\Support\Profiler;
use Rsgrinko\Proton\View\View;

if (!Profiler::enabled()) {
    return;
}

$repeats = Profiler::repeats(3);
$slowest = Profiler::slowest(5);
?>
<details class="profiler">
    <summary>
        база: <?= Profiler::count() ?> запр. / <?= View::e((string) Profiler::time()) ?> мс
        · страница: <?= View::e((string) Profiler::elapsed()) ?> мс
        · память: <?= View::e(\Rsgrinko\Proton\Support\Str::bytes(memory_get_peak_usage(true))) ?>
        <?php if ($repeats !== []) { ?>
            · <span class="warn">повторов: <?= count($repeats) ?></span>
        <?php } ?>
    </summary>

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
                    <td class="mono"><?= View::e(mb_substr($query['sql'], 0, 300)) ?></td>
                </tr>
            <?php } ?>
        </table>
    <?php } ?>
</details>
