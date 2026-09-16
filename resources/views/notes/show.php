<?php

declare(strict_types=1);

/**
 * Карточка заметки.
 *
 * @var \App\Models\Note $note
 * @var \Rsgrinko\Proton\Models\User|null $author
 * @var array<int, \Rsgrinko\Proton\Files\Attachment> $attachments
 * @var array<int, \Rsgrinko\Proton\Comments\Comment> $comments
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

<?php if ($attachments !== []) { ?>
    <div class="card">
        <h2>Вложения (<?= count($attachments) ?>)</h2>

        <div class="attachments">
            <?php foreach ($attachments as $attachment) { ?>
                <?php $url = View::route('notes.attachment', ['id' => $note->id(), 'attachment' => $attachment->id()]); ?>
                <div class="attachment">
                    <a href="<?= View::e($url) ?>">
                        <?php if ($attachment->isImage()) { ?>
                            <img src="<?= View::e($url) ?>" alt="<?= View::e((string) $attachment->raw('name')) ?>">
                        <?php } else { ?>
                            <span class="file-icon"><?= View::e(strtoupper(pathinfo((string) $attachment->raw('name'), PATHINFO_EXTENSION)) ?: 'файл') ?></span>
                        <?php } ?>
                    </a>

                    <div class="small break"><?= View::e((string) $attachment->raw('name')) ?></div>
                    <div class="muted small"><?= View::e($attachment->readableSize()) ?></div>

                    <?php if (View::can('note.edit', $note)) { ?>
                        <form method="post" action="<?= View::e(View::route('notes.detach', ['id' => $note->id()])) ?>">
                            <?= View::csrf() ?>
                            <input type="hidden" name="attachment" value="<?= $attachment->id() ?>">
                            <button type="submit" class="small danger" data-confirm="Убрать вложение? Файл удалится.">Убрать</button>
                        </form>
                    <?php } ?>
                </div>
            <?php } ?>
        </div>
    </div>
<?php } ?>

<div class="card">
    <h2>Комментарии (<?= count($comments) ?>)</h2>

    <?php if ($comments === []) { ?>
        <p class="muted small">Пока никто не написал.</p>
    <?php } else { ?>
        <?php $viewerId = View::viewer()->id(); ?>
        <div class="comments">
            <?php foreach ($comments as $comment) { ?>
                <?php $author = $comment->author; ?>
                <div class="comment">
                    <div class="row">
                        <strong class="small"><?= View::e($author === null ? 'удалённый пользователь' : (string) $author->login) ?></strong>
                        <span class="muted small"><?= View::e(View::ago((string) $comment->raw('created_at'))) ?></span>
                        <span class="spacer"></span>

                        <?php if ((int) $comment->raw('user_id') === $viewerId || View::can('notes.manage')) { ?>
                            <form method="post" action="<?= View::e(View::route('notes.comment.delete', ['id' => $note->id()])) ?>">
                                <?= View::csrf() ?>
                                <input type="hidden" name="comment" value="<?= $comment->id() ?>">
                                <button type="submit" class="small danger">Убрать</button>
                            </form>
                        <?php } ?>
                    </div>
                    <p class="break small"><?= View::e((string) $comment->raw('body')) ?></p>
                </div>
            <?php } ?>
        </div>
    <?php } ?>

    <form method="post" action="<?= View::e(View::route('notes.comment', ['id' => $note->id()])) ?>" style="margin-top: 12px;">
        <?= View::csrf() ?>
        <textarea name="body" rows="3" placeholder="Написать комментарий…" required></textarea>
        <button type="submit" class="primary" style="margin-top: 8px;">Отправить</button>
    </form>
</div>

<?= View::partial('history', ['entity' => 'note', 'id' => $note->id()]) ?>

<p><a href="<?= View::e(View::route('notes.index')) ?>">← ко всем заметкам</a></p>
