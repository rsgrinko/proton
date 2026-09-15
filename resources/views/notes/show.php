<?php

declare(strict_types=1);

/**
 * Карточка заметки.
 *
 * @var \App\Models\Note $note
 * @var \Rsgrinko\Proton\Models\User|null $author
 */

use Rsgrinko\Proton\Support\SignedUrl;
use Rsgrinko\Proton\View\View;
?>
<div class="row">
    <h1 style="margin: 0;"><?= View::e((string) $note->title) ?></h1>
    <span class="spacer"></span>

    <?php if (View::can('note.edit', $note)) { ?>
        <a class="btn" href="<?= View::e(View::route('notes.edit', ['id' => $note->id()])) ?>">Править</a>
    <?php } ?>

    <?php if (View::can('note.delete', $note)) { ?>
        <form method="post" action="<?= View::e(View::route('notes.delete', ['id' => $note->id()])) ?>">
            <?= View::csrf() ?>
            <button type="submit" class="danger">Удалить</button>
        </form>
    <?php } ?>
</div>

<div class="grid cols-2" style="margin-top: 16px;">
    <div class="card">
        <h2>Текст</h2>
        <?php if (trim((string) $note->body) === '') { ?>
            <p class="muted">Пусто.</p>
        <?php } else { ?>
            <pre class="break"><?= View::e((string) $note->body) ?></pre>
        <?php } ?>
    </div>

    <div class="card">
        <h2>Свойства</h2>

        <dl class="props">
            <dt>Автор</dt>
            <dd><?= View::e($author === null ? '—' : (string) $author->login) ?></dd>

            <dt>Адресная часть</dt>
            <dd class="mono"><?= View::e((string) $note->raw('slug')) ?></dd>

            <dt>Закреплена</dt>
            <dd><?= $note->pinned ? 'да' : 'нет' ?></dd>

            <dt>Создана</dt>
            <dd><?= View::e(View::date((string) $note->raw('created_at'))) ?></dd>

            <dt>Изменена</dt>
            <dd><?= View::e(View::date((string) $note->raw('updated_at'))) ?></dd>

            <dt>Файл</dt>
            <dd>
                <?php if ($note->hasFile()) { ?>
                    <a href="<?= View::e(View::route('notes.file', ['id' => $note->id()])) ?>"><?= View::e((string) $note->raw('file_name')) ?></a>

                    <?php $shared = SignedUrl::absolute('notes.file.shared', ['id' => $note->id()], 86400); ?>
                    <div class="muted small" style="margin-top: 6px;">
                        Ссылка на сутки, вход по ней не нужен:
                        <?= View::copy($shared, 'скопировать') ?>
                    </div>
                <?php } else { ?>
                    —
                <?php } ?>
            </dd>
        </dl>
    </div>
</div>

<?= View::partial('history', ['entity' => 'note', 'id' => $note->id()]) ?>

<p><a href="<?= View::e(View::route('notes.index')) ?>">← ко всем заметкам</a></p>
