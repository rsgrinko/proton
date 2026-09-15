<?php

declare(strict_types=1);

/**
 * Логи: список файлов и последние строки выбранного.
 *
 * @var array<int, array{name: string, path: string, size: int, mtime: int}> $files
 * @var string $current
 * @var array<int, array{time: string, level: string, channel: string, message: string, context: string}> $lines
 * @var array<string, string> $filters
 * @var int $total сколько строк подошло под фильтр
 * @var int $page
 * @var int $pages
 * @var int $depth сколько последних строк файла разбирали
 */

use Rsgrinko\Proton\Support\Str;
use Rsgrinko\Proton\View\View;

$badges = ['error' => 'error', 'warning' => 'warn', 'info' => 'info', 'debug' => 'muted'];

/**
 * Контекст в файле лежит одной строкой — так его удобно грепать. На экране
 * наоборот: разворачиваем в читаемый JSON, иначе разбирать трассу невозможно.
 */
$pretty = static function (string $context): string {
    $decoded = json_decode($context, true);

    if (!is_array($decoded)) {
        return $context;
    }

    return (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
};
?>
<h1>Логи</h1>

<div class="card">
    <form method="get" action="<?= View::e(View::route('admin.logs')) ?>">
        <div class="filters">
            <label>
                <span>Файл</span>
                <select name="file">
                    <?php foreach ($files as $file) { ?>
                        <option value="<?= View::e($file['name']) ?>" <?= $current === $file['name'] ? 'selected' : '' ?>>
                            <?= View::e($file['name']) ?> (<?= View::e(Str::bytes($file['size'])) ?>)
                        </option>
                    <?php } ?>
                </select>
            </label>

            <label>
                <span>Уровень</span>
                <select name="level">
                    <option value="">любой</option>
                    <?php foreach (['debug' => 'отладка', 'info' => 'информация', 'warning' => 'предупреждение', 'error' => 'ошибка'] as $code => $label) { ?>
                        <option value="<?= View::e($code) ?>" <?= $filters['level'] === $code ? 'selected' : '' ?>><?= View::e($label) ?></option>
                    <?php } ?>
                </select>
            </label>

            <label>
                <span>Поиск по строке</span>
                <input type="search" name="q" value="<?= View::e($filters['q']) ?>">
            </label>
        </div>

        <div class="filter-actions row">
            <button type="submit" class="primary">Показать</button>
            <a class="btn" href="<?= View::e(View::route('admin.logs')) ?>">Сбросить</a>
            <span class="spacer"></span>
            <span class="muted small">Подошло строк: <?= (int) $total ?> (разбирали последние <?= (int) $depth ?> файла)</span>
        </div>
    </form>
</div>

<div class="card">
    <?php if ($files === []) { ?>
        <p class="muted">Файлов логов ещё нет.</p>
    <?php } elseif ($lines === []) { ?>
        <p class="muted">Под фильтр ничего не подошло.</p>
    <?php } else { ?>
        <div class="table-wrap">
            <table class="list logs">
                <tr class="head">
                    <th>Время</th>
                    <th>Уровень</th>
                    <th class="hide-sm">Канал</th>
                    <th>Сообщение</th>
                </tr>

                <?php foreach ($lines as $line) { ?>
                    <tr>
                        <td class="muted small nowrap"><?= View::e($line['time']) ?></td>
                        <td><span class="badge <?= View::e($badges[$line['level']] ?? 'muted') ?>"><?= View::e($line['level']) ?></span></td>
                        <td class="hide-sm muted small"><?= View::e($line['channel']) ?></td>
                        <td>
                            <?= View::e(Str::limit($line['message'], 200)) ?>

                            <?php if ($line['context'] !== '') { ?>
                                <?php /* Контекст с трассой бывает на десятки килобайт: под спойлером
                                        он не распирает таблицу, но остаётся под рукой */ ?>
                                <details class="log-context">
                                    <summary class="small">контекст (<?= View::e(Str::bytes(strlen($line['context']))) ?>)</summary>
                                    <pre class="break small"><?= View::e($pretty($line['context'])) ?></pre>
                                </details>
                            <?php } ?>
                        </td>
                    </tr>
                <?php } ?>
            </table>
        </div>

        <?= View::partial('pagination', [
            'route'  => 'admin.logs',
            'page'   => $page,
            'pages'  => $pages,
            'params' => ['file' => $current, 'level' => $filters['level'], 'q' => $filters['q']],
        ]) ?>
    <?php } ?>
</div>
