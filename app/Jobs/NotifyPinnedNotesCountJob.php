<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Notifications\PinnedNotesCountedNotification;
use Rsgrinko\Proton\Models\User;
use Rsgrinko\Proton\Notifications\Notify;
use Rsgrinko\Proton\Queue\Job;
use Rsgrinko\Proton\Support\Logger;

/**
 * Второй шаг цепочки-примера: считает CountPinnedNotesJob, сообщает —
 * этот. Ставится в очередь не сам по себе, а через Queue::chain() —
 * см. `NotesPinnedReportCommand`.
 *
 * payload приходит не от того, кто ставил цепочку, а от предыдущего шага
 * (carryToNext()) — id пользователя и число заметок появляются здесь только
 * потому, что первый шаг их туда положил.
 */
final class NotifyPinnedNotesCountJob extends Job
{
    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload): void
    {
        $userId = (int) ($payload['user_id'] ?? 0);
        $count  = (int) ($payload['count'] ?? 0);

        $user = User::find($userId);

        if ($user === null) {
            // Пользователя могли удалить, пока первый шаг считал заметки —
            // цепочке не на кого ронять исключение, просто нечего сообщать
            (new Logger('jobs'))->warning('Отчёт по заметкам некому отдать', ['user_id' => $userId]);

            return;
        }

        Notify::send($user, new PinnedNotesCountedNotification($count));
    }
}
