<?php

declare(strict_types=1);

/**
 * Резервные копии базы.
 *
 * @var array<int, array{name: string, size: int, created: string}> $files
 * @var int $keep сколько копий держим
 * @var string $schedule время ежедневной копии или пусто
 * @var string $dir каталог с копиями
 * @var bool $ftp задан ли FTP для отправки копий
 */

use Rsgrinko\Proton\Backup\Backup;
use Rsgrinko\Proton\View\View;
?>
<h1>Резервные копии</h1>

<div class="card">
    <p class="muted small">
        Копия делается средствами самой базы и сразу проверяется: файл открывается
        заново и читается. Негодная копия не сохраняется — лучше её отсутствие, чем
        уверенность, что она есть.
    </p>

    <table class="list">
        <tr>
            <th>Каталог</th>
            <td class="mono small break"><?= View::e($dir) ?></td>
        </tr>
        <tr>
            <th>Держим копий</th>
            <td><?= $keep ?> — лишние удаляются после новой</td>
        </tr>
        <tr>
            <th>По расписанию</th>
            <td>
                <?php if ($schedule !== '') { ?>
                    каждый день в <?= View::e($schedule) ?> (делает воркер)
                <?php } else { ?>
                    <span class="muted">выключено — задайте <span class="mono">BACKUP_SCHEDULE</span> в .env</span>
                <?php } ?>
            </td>
        </tr>
        <tr>
            <th>Отправка на FTP</th>
            <td>
                <?php if ($ftp) { ?>
                    включена — свежая копия по расписанию уезжает сама, старые можно отправить кнопкой
                <?php } else { ?>
                    <span class="muted">выключено — задайте <span class="mono">FTP_HOST</span> в .env</span>
                <?php } ?>
            </td>
        </tr>
    </table>

    <div class="row" style="margin-top: 12px;">
        <form method="post" action="<?= View::e(View::route('admin.backups.store')) ?>">
            <?= View::csrf() ?>
            <button type="submit" class="primary">Сделать копию</button>
        </form>
    </div>

    <p class="muted small">
        Восстановление — только из консоли: <span class="mono">php bin/proton backup:restore &lt;файл&gt; --force</span>.
        Оно заменяет все данные, поэтому кнопки для него нет.
    </p>
</div>

<div class="card">
    <h2>Файлы</h2>

    <?php if ($files === []) { ?>
        <p class="muted">Копий пока нет.</p>
    <?php } else { ?>
        <div class="table-wrap">
            <table class="list">
                <tr class="head">
                    <th>Файл</th>
                    <th>Размер</th>
                    <th class="hide-sm">Когда</th>
                    <th></th>
                </tr>

                <?php foreach ($files as $file) { ?>
                    <tr>
                        <td class="mono small break"><?= View::e($file['name']) ?></td>
                        <td><?= View::e(Backup::size($file['size'])) ?></td>
                        <td class="hide-sm muted small"><?= View::e(View::date($file['created'])) ?></td>
                        <td class="right">
                            <div class="row end">
                                <form method="post" action="<?= View::e(View::route('admin.backups.download')) ?>">
                                    <?= View::csrf() ?>
                                    <input type="hidden" name="name" value="<?= View::e($file['name']) ?>">
                                    <button type="submit">Скачать</button>
                                </form>

                                <form method="post" action="<?= View::e(View::route('admin.backups.check')) ?>">
                                    <?= View::csrf() ?>
                                    <input type="hidden" name="name" value="<?= View::e($file['name']) ?>">
                                    <button type="submit">Проверить</button>
                                </form>

                                <?php if ($ftp) { ?>
                                    <form method="post" action="<?= View::e(View::route('admin.backups.ship')) ?>">
                                        <?= View::csrf() ?>
                                        <input type="hidden" name="name" value="<?= View::e($file['name']) ?>">
                                        <button type="submit">Отправить на FTP</button>
                                    </form>
                                <?php } ?>

                                <form method="post" action="<?= View::e(View::route('admin.backups.delete')) ?>">
                                    <?= View::csrf() ?>
                                    <input type="hidden" name="name" value="<?= View::e($file['name']) ?>">
                                    <button type="submit" class="danger">Удалить</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php } ?>
            </table>
        </div>

        <?= View::partial('pagination', [
            'route'  => 'admin.backups',
            'page'   => $page,
            'pages'  => $pages,
            'params' => [],
        ]) ?>
    <?php } ?>
</div>
