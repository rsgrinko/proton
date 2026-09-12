<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Note;
use Rsgrinko\Proton\Queue\Job;
use Rsgrinko\Proton\Support\Logger;
use Rsgrinko\Proton\Support\Str;

/**
 * Пример обычной фоновой задачи — не про почту.
 *
 * Пересчитывает адресные части заметок порциями: такую работу нельзя делать
 * в запросе, зато в очереди она никому не мешает.
 *
 *     Queue::push(RebuildNoteSlugsJob::class, ['from' => 0]);
 *
 * Задача сама ставит себе продолжение, пока не разберёт всё: так очередь
 * обходит большие таблицы, не занимая воркер часами и переживая перезапуск.
 */
final class RebuildNoteSlugsJob extends Job
{
    /** Сколько записей обрабатываем за раз */
    private const CHUNK = 200;

    public function attempts(): int
    {
        return 3;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload): void
    {
        $from = (int) ($payload['from'] ?? 0);

        /** @var array<int, Note> $notes */
        $notes = Note::query()
            ->where('id', '>', $from)
            ->orderBy('id')
            ->limit(self::CHUNK)
            ->get();

        if ($notes === []) {
            (new Logger('jobs'))->info('Адреса заметок пересчитаны полностью');

            return;
        }

        $last = $from;

        foreach ($notes as $note) {
            $note->forceFill(['slug' => Str::slug((string) $note->raw('title'))])->save();

            $last = $note->id();
        }

        // Следующая порция — отдельной задачей: воркер между ними успеет
        // заняться остальной очередью
        \Rsgrinko\Proton\Queue\Queue::push(self::class, ['from' => $last]);
    }
}
