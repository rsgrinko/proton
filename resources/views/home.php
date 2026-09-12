<?php

declare(strict_types=1);

/**
 * Главная страница вошедшего.
 *
 * @var \Rsgrinko\Proton\Access\Viewer $viewer
 * @var int $notes
 * @var array<int, \App\Models\Note> $latest
 */

use Rsgrinko\Proton\Support\Config;
use Rsgrinko\Proton\View\View;
?>
<h1>Здравствуйте, <?= View::e($viewer->name()) ?></h1>

<div class="grid cols-2">
    <div class="card">
        <h2>Быстрый старт</h2>
        <p class="muted small">
            Это скелет приложения на Proton. Замените этот раздел своим — заметки, панель,
            API и тесты лежат рядом как образец.
        </p>
        <ul class="small">
            <li>Раздел-пример: <a href="<?= View::e(View::route('notes.index')) ?>">заметки</a> (список, форма, файл, мягкое удаление)</li>
            <li>Свой профиль и устройства: <a href="<?= View::e(View::route('profile')) ?>">профиль</a></li>
            <?php if (View::can('system.view')) { ?>
                <li>Управление: <a href="<?= View::e(View::route('admin.dashboard')) ?>">панель</a></li>
            <?php } ?>
            <li>Документация: каталог <span class="mono">docs/</span> в корне проекта</li>
        </ul>
    </div>

    <div class="card stat">
        <div class="label">Ваших заметок</div>
        <div class="value"><?= (int) $notes ?></div>
        <p class="muted small">Приложение: <?= View::e((string) Config::get('app.name', 'Proton')) ?>,
            окружение <?= View::e((string) Config::get('app.env', 'local')) ?></p>
    </div>
</div>

<?php if ($latest !== []) { ?>
    <div class="card">
        <h2>Последние заметки</h2>

        <div class="table-wrap">
            <table class="list">
                <tr class="head">
                    <th>Название</th>
                    <th class="hide-sm">Изменена</th>
                </tr>

                <?php foreach ($latest as $note) { ?>
                    <tr>
                        <td><a href="<?= View::e(View::route('notes.show', ['id' => $note->id()])) ?>"><?= View::e((string) $note->title) ?></a></td>
                        <td class="hide-sm muted small"><?= View::e(View::ago((string) $note->raw('updated_at'))) ?></td>
                    </tr>
                <?php } ?>
            </table>
        </div>
    </div>
<?php } ?>
