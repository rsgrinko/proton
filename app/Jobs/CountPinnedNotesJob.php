<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Note;
use Rsgrinko\Proton\Queue\Job;

/**
 * Первый шаг цепочки-примера (`Queue::chain()`) — образец на замену, если
 * задача расползается на несколько шагов подряд (посчитать -> сообщить,
 * подготовить файл -> отправить). Запускается командой
 * `php bin/proton notes:pinned-report <логин>` (см. NotesPinnedReportCommand).
 *
 * Считает закреплённые заметки пользователя. Результат неизвестен заранее —
 * его нельзя положить в payload второго шага при постановке в очередь,
 * поэтому он уезжает через carryToNext(), а не аргументом задачи.
 */
final class CountPinnedNotesJob extends Job
{
    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload): void
    {
        $userId = (int) ($payload['user_id'] ?? 0);

        $count = Note::query()
            ->where('user_id', $userId)
            ->where('pinned', 1)
            ->count();

        // Второй шаг (NotifyPinnedNotesCountJob) получит это в payload —
        // id пользователя нужно передать явно, сам он в carry не попадает
        $this->carryToNext(['user_id' => $userId, 'count' => $count]);
    }
}
