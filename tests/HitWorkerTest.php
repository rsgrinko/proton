<?php

declare(strict_types=1);

/**
 * Разбор очереди на хите — подстраховка без отдельного демона.
 */

use Rsgrinko\Proton\Models\Setting;
use Rsgrinko\Proton\Queue\HitWorker;
use Rsgrinko\Proton\Queue\Job;
use Rsgrinko\Proton\Queue\Queue;

final class HitWorkerTestJob extends Job
{
    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload): void
    {
        Setting::set('test:hitworker:done', '1');
    }
}

test('по умолчанию выключено — очередь хитом не разбирается', function (): void {
    withOwnDatabase(static function (): void {
        Setting::forget('test:hitworker:done');
        Queue::push(HitWorkerTestJob::class);

        HitWorker::run();

        assertSame('', Setting::get('test:hitworker:done'));
        assertSame(1, Queue::stats()['queued'] ?? 0, 'задача осталась в очереди');
    });
});

test('включённое — разбирает очередь прямо на хите', function (): void {
    withOwnDatabase(static function (): void {
        withConfig(['queue.process_on_hit' => true], static function (): void {
            Setting::forget('test:hitworker:done');
            Queue::push(HitWorkerTestJob::class);

            HitWorker::run();

            assertSame('1', Setting::get('test:hitworker:done'));
            assertSame(0, Queue::stats()['queued'] ?? 0);
        });
    });
});
