<?php

declare(strict_types=1);

namespace App\Notifications;

use Rsgrinko\Proton\Http\Router;
use Rsgrinko\Proton\Notifications\Notification;
use Rsgrinko\Proton\Notifications\Notify;

/**
 * Итог отчёта из очереди — второй шаг цепочки NotifyPinnedNotesCountJob
 * доставляет его пользователю. В отличие от NoteCreatedNotification (только
 * лента), здесь оба канала: письмо уместно, потому что отчёт разовый,
 * а не на каждое действие.
 */
final class PinnedNotesCountedNotification extends Notification
{
    public function __construct(private readonly int $count)
    {
    }

    public function type(): string
    {
        return 'notes.pinned_report';
    }

    public function title(): string
    {
        return 'Отчёт по закреплённым заметкам';
    }

    public function body(): string
    {
        return 'Закреплённых заметок: ' . $this->count;
    }

    public function url(): string
    {
        // Адрес с доменом: уведомление уходит и письмом, а там путь без него не кликается
        return Router::absolute('notes.index');
    }

    /**
     * @return array<int, string>
     */
    public function channels(): array
    {
        return [Notify::DATABASE, Notify::MAIL];
    }
}
