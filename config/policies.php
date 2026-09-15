<?php

declare(strict_types=1);

/**
 * Правила на конкретную запись.
 *
 * Право (`config/permissions.php`) решает, пускать ли в раздел; правило —
 * можно ли трогать именно эту строку. Правила без записи не бывает: если
 * записи нет, вызывается право с тем же именем.
 *
 *     Policy::define('note.edit', static fn (Viewer $viewer, Note $note): bool => …);
 *     $this->authorize('note.edit', $note);   // в контроллере
 *     View::can('note.edit', $note)           // в шаблоне
 */

use App\Models\Note;
use Rsgrinko\Proton\Access\Policy;
use Rsgrinko\Proton\Access\Viewer;

// Свою заметку правит тот, кто может править заметки вообще; чужую — только
// тот, кому видны чужие записи (право data.all)
Policy::define('note.edit', static function (Viewer $viewer, mixed $note): bool {
    if (!$viewer->can('notes.manage')) {
        return false;
    }

    return Policy::owns($viewer, $note) || $viewer->isAdmin();
});

// Удаление строже правки: чужое удаляет только полный доступ
Policy::define('note.delete', static function (Viewer $viewer, mixed $note): bool {
    if (!$viewer->can('notes.manage')) {
        return false;
    }

    return Policy::owns($viewer, $note) || $viewer->isAdmin();
});

// Закрепить заметку наверху списка может только тот, кто видит все: иначе
// каждый закрепит своё и список перестанет что-либо значить
Policy::define('note.pin', static fn (Viewer $viewer, mixed $note): bool => $viewer->isAdmin());
