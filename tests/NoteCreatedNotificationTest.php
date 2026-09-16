<?php

declare(strict_types=1);

/**
 * Пример «событие -> уведомление»: config/events.php подписывает
 * NoteCreatedNotification на note.created, см. app/Notifications/NoteCreatedNotification.php.
 */

use App\Models\Note;
use Rsgrinko\Proton\Access\Viewer;
use Rsgrinko\Proton\Events\Events;
use Rsgrinko\Proton\Models\Role;
use Rsgrinko\Proton\Models\User;
use Rsgrinko\Proton\Models\UserNotification;

test('note.created заводит уведомление тем, кто видит чужие данные', function (): void {
    $admin = User::register('note_notify_admin_' . bin2hex(random_bytes(3)), 'секрет123', [
        'email'   => 'note_notify' . bin2hex(random_bytes(3)) . '@example.com',
        'role_id' => Role::admin()?->id() ?? 0,
    ]);

    assertTrue(Viewer::fromUser($admin)->can(\Rsgrinko\Proton\Access\Permission::DATA_ALL), 'у встроенного администратора право есть само собой');

    $before = UserNotification::unreadFor($admin->id());

    $note = Note::create(['title' => 'Заметка для примера уведомления']);

    try {
        // note.created объявляет контроллер после успешного сохранения
        // (NotesController::store()), а не сама модель — зовём событие так же,
        // как это делает реальный запрос POST /notes
        Events::fire('note.created', ['note' => $note]);

        assertSame($before + 1, UserNotification::unreadFor($admin->id()));

        $notification = UserNotification::query()
            ->where('user_id', $admin->id())
            ->orderBy('id', 'desc')
            ->first();

        assertNotNull($notification);
        assertContains((string) $note->title, (string) $notification->raw('title'));
    } finally {
        $note->forceDelete();
        UserNotification::query()->where('user_id', $admin->id())->forceDelete();
        $admin->forceDelete();
    }
});
