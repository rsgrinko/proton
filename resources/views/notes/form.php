<?php

declare(strict_types=1);

/**
 * Форма заметки — одна на создание и на правку.
 *
 * @var \App\Models\Note $note
 */

use Rsgrinko\Proton\View\View;

$isNew  = !$note->existsInDatabase();
$action = $isNew
    ? View::route('notes.create')
    : View::route('notes.edit', ['id' => $note->id()]);
?>
<h1><?= $isNew ? 'Новая заметка' : 'Правка заметки' ?></h1>

<div class="card">
    <form method="post" action="<?= View::e($action) ?>" enctype="multipart/form-data">
        <?= View::csrf() ?>

        <label>
            <span>Название</span>
            <input type="text" name="title" value="<?= View::e((string) $note->title) ?>" autofocus required>
        </label>

        <label>
            <span>Текст</span>
            <textarea name="body"><?= View::e((string) $note->body) ?></textarea>
        </label>

        <label class="inline" style="margin-bottom: 12px;">
            <input type="checkbox" name="pinned" value="1" <?= $note->pinned ? 'checked' : '' ?>>
            <span>Закрепить наверху списка</span>
        </label>

        <label>
            <span>Файл<?= $note->hasFile() ? ' (сейчас: ' . View::e((string) $note->raw('file_name')) . ')' : '' ?></span>
            <input type="file" name="attachment">
        </label>

        <div class="row">
            <button type="submit" class="primary">Сохранить</button>
            <a class="btn" href="<?= View::e(View::route('notes.index')) ?>">Отмена</a>
        </div>
    </form>
</div>
