<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Note;
use Rsgrinko\Proton\Http\Router;
use Rsgrinko\Proton\Notifications\Notification;
use Rsgrinko\Proton\Notifications\Notify;

/**
 * Пример своего уведомления: строка в ленте того, кто видит чужие данные,
 * на каждую новую заметку. На боевой раздел так шумно уведомлять незачем —
 * здесь это только образец того, как событие превращается в уведомление,
 * заодно показывающий демонстрационный раздел «Заметки» целиком.
 *
 * Заводится не сама по себе, а из подписки в config/events.php:
 *
 *     Events::listen('note.created', static function (array $payload): void {
 *         if ($payload['note'] instanceof Note) {
 *             Notify::toPermission(Permission::DATA_ALL, new NoteCreatedNotification($payload['note']));
 *         }
 *     });
 */
final class NoteCreatedNotification extends Notification
{
    public function __construct(private readonly Note $note)
    {
    }

    public function type(): string
    {
        return 'note.created';
    }

    public function title(): string
    {
        return 'Новая заметка: ' . $this->note->title;
    }

    public function body(): string
    {
        return $this->note->excerpt(140);
    }

    public function url(): string
    {
        return Router::url('notes.show', ['id' => $this->note->id()]);
    }

    /**
     * Только лента — письмо на каждую заметку было бы уже слишком.
     *
     * @return array<int, string>
     */
    public function channels(): array
    {
        return [Notify::DATABASE];
    }
}
