<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Note;
use Rsgrinko\Proton\Database\Model\Model;
use Rsgrinko\Proton\Files\Attachment;

/**
 * Насовсем удалённая из корзины заметка не забирает с собой вложения сама:
 * Model ничего не знает про Attachment, а мягкое удаление их нарочно не
 * трогает (заметка вернётся из корзины — вернутся и файлы). После forceDelete
 * возвращаться уже некуда, а строки вложений и сами файлы без наблюдателя
 * оставались бы висеть вечно — `files:orphans` их не находит, потому что для
 * него файл с существующей записью в attachments не сирота.
 */
final class NoteObserver
{
    public function deleted(Model $model, bool $force = false): void
    {
        if ($force && $model instanceof Note) {
            Attachment::detachAll('note', $model->id());
        }
    }
}
