<?php

declare(strict_types=1);

/**
 * Цепочка задач: Queue::chain() ставит следующий шаг только после успеха
 * предыдущего, carryToNext() передаёт то, чего заранее в payload не было.
 */

use Rsgrinko\Proton\Models\Setting;
use Rsgrinko\Proton\Queue\Job;
use Rsgrinko\Proton\Queue\Queue;
use Rsgrinko\Proton\Queue\Worker;

/**
 * Первый шаг: то, что «появляется только сейчас», отдаёт следующему.
 */
final class ChainFirstJob extends Job
{
    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload): void
    {
        $this->carryToNext(['generated' => 'значение-' . (string) ($payload['seed'] ?? '')]);
    }
}

/**
 * Второй шаг: получает и то, что было в его собственном payload заранее,
 * и то, что подмешал первый шаг через carryToNext().
 */
final class ChainSecondJob extends Job
{
    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload): void
    {
        Setting::set(
            'test:chain:second',
            (string) ($payload['generated'] ?? '') . '/' . (string) ($payload['static'] ?? '')
        );
    }
}

/**
 * Падает всегда и сразу насовсем — на ней проверяется, что цепочка не
 * продолжается после того, как шаг умер.
 */
final class ChainAlwaysFailsJob extends Job
{
    public function attempts(): int
    {
        return 1;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload): void
    {
        throw new RuntimeException('падает специально');
    }
}

test('очередь: цепочка передаёт следующему шагу то, что появилось только сейчас', function (): void {
    withOwnDatabase(static function (): void {
        Setting::forget('test:chain:second');

        Queue::chain([
            [ChainFirstJob::class, ['seed' => '7']],
            [ChainSecondJob::class, ['static' => 'из шага']],
        ]);

        assertSame(1, Queue::stats()[Queue::QUEUED] ?? 0, 'в очереди пока только первый шаг');

        (new Worker())->run(true);

        assertSame('значение-7/из шага', Setting::get('test:chain:second', ''));
        assertSame(0, Queue::stats()[Queue::QUEUED] ?? 0, 'оба шага разобраны');
        assertSame(2, Queue::stats()[Queue::DONE] ?? 0);
    });
});

test('очередь: цепочка останавливается, если шаг умер насовсем', function (): void {
    withOwnDatabase(static function (): void {
        Setting::forget('test:chain:second');

        // Первый шаг — просто имя класса, без явного payload: normalizeStep()
        // должен принять и такую запись, не только пару [класс, payload]
        Queue::chain([
            ChainAlwaysFailsJob::class,
            [ChainSecondJob::class, ['static' => 'не должно случиться']],
        ]);

        (new Worker())->run(true);

        assertSame('', Setting::get('test:chain:second', ''), 'второй шаг не выполнился');
        assertSame(1, Queue::stats()[Queue::DEAD] ?? 0, 'первый шаг мёртв — попытка была одна');
        assertSame(0, Queue::stats()[Queue::QUEUED] ?? 0, 'второй шаг в очередь не попал');
    });
});

test('очередь: пустая цепочка не проходит молча', function (): void {
    assertThrows(static function (): void {
        Queue::chain([]);
    });
});
