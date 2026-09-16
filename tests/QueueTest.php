<?php

declare(strict_types=1);

/**
 * Очередь задач: захват, повторы, backoff, воркер и расписание.
 */

use Rsgrinko\Proton\Database\Connection;
use Rsgrinko\Proton\Database\Lock;
use Rsgrinko\Proton\Models\Setting;
use Rsgrinko\Proton\Queue\Job;
use Rsgrinko\Proton\Queue\Queue;
use Rsgrinko\Proton\Queue\Scheduler;
use Rsgrinko\Proton\Queue\Worker;

/**
 * Задача, которая просто отмечается в настройках: так видно, что она была.
 */
final class QueueTestJob extends Job
{
    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload): void
    {
        Setting::set('test:job:done', (string) ($payload['mark'] ?? '1'));
    }
}

/**
 * Задача, которая всегда падает: на ней проверяются повторы.
 */
final class QueueFailingJob extends Job
{
    public function attempts(): int
    {
        return 2;
    }

    public function backoff(int $attempt): int
    {
        return 60;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload): void
    {
        throw new RuntimeException('намеренная поломка');
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function failed(array $payload, string $error): void
    {
        Setting::set('test:job:failed', $error);
    }
}

test('очередь: задача ставится, захватывается и завершается', function (): void {
    withOwnDatabase(static function (): void {
        $id = Queue::push(QueueTestJob::class, ['mark' => 'раз']);

        assertTrue($id > 0);
        assertSame(1, Queue::stats()[Queue::QUEUED] ?? 0);

        $job = assertNotNull(Queue::claim());

        assertSame($id, (int) $job['id']);
        assertSame(1, (int) $job['attempts'], 'захват увеличивает счётчик попыток');

        // Захваченную задачу второй воркер взять уже не может — иначе она
        // выполнится дважды
        assertNull(Queue::claim(), 'вторая выборка не должна ничего вернуть');

        Queue::complete($id);

        assertSame(1, Queue::stats()[Queue::DONE] ?? 0);
    });
});

test('очередь: отложенная задача не берётся раньше срока', function (): void {
    withOwnDatabase(static function (): void {
        Queue::push(QueueTestJob::class, [], 3600);

        assertNull(Queue::claim(), 'срок ещё не наступил');
    });
});

test('очередь: разные очереди разбираются отдельно', function (): void {
    withOwnDatabase(static function (): void {
        Queue::push(QueueTestJob::class, [], 0, 'отчёты');

        assertNull(Queue::claim(), 'в очереди по умолчанию пусто');
        assertNotNull(Queue::claim('отчёты'));
    });
});

test('очередь: ошибка возвращает задачу с паузой, попытки кончились — падает', function (): void {
    withOwnDatabase(static function (): void {
        Queue::push(QueueFailingJob::class);

        (new Worker())->run(true);

        $row = assertNotNull(Connection::instance()->selectOne('SELECT * FROM jobs LIMIT 1'));

        assertSame(Queue::QUEUED, (string) $row['status'], 'после первой ошибки задача ждёт повтора');
        assertTrue(strtotime((string) $row['available_at']) > time(), 'повтор отложен на backoff');

        // Двигаем срок в прошлое и добиваем последнюю попытку
        Connection::instance()->update('jobs', ['available_at' => Connection::now()], ['id' => (int) $row['id']]);

        (new Worker())->run(true);

        $row = assertNotNull(Connection::instance()->selectOne('SELECT * FROM jobs LIMIT 1'));

        assertSame(Queue::DEAD, (string) $row['status'], 'попытки кончились — задача мёртвая');
        assertContains('намеренная поломка', (string) $row['error']);
        assertContains('намеренная поломка', Setting::get('test:job:failed'), 'обработчик отказа зовётся');
    });
});

test('очередь: воркер разбирает очередь до дна', function (): void {
    withOwnDatabase(static function (): void {
        Queue::push(QueueTestJob::class, ['mark' => 'первая']);
        Queue::push(QueueTestJob::class, ['mark' => 'вторая']);

        $result = (new Worker())->run(true);

        assertSame(2, $result['done']);
        assertSame(0, $result['failed']);
        assertSame('вторая', Setting::get('test:job:done'));
        assertSame(0, Queue::stats()[Queue::QUEUED] ?? 0);
    });
});

test('очередь: повтор возвращает упавшую задачу и обнуляет попытки', function (): void {
    withOwnDatabase(static function (): void {
        $id = Queue::push(QueueFailingJob::class);

        Connection::instance()->update('jobs', ['status' => Queue::FAILED, 'attempts' => 2], ['id' => $id]);

        assertTrue(Queue::retry($id));

        $row = assertNotNull(Connection::instance()->selectOne('SELECT * FROM jobs WHERE id = :id', ['id' => $id]));

        assertSame(Queue::QUEUED, (string) $row['status']);
        assertSame(0, (int) $row['attempts']);
    });
});

test('очередь: зависшие в работе возвращаются, выполненные подчищаются', function (): void {
    withOwnDatabase(static function (): void {
        $id = Queue::push(QueueTestJob::class);

        Connection::instance()->update('jobs', [
            'status'      => Queue::RUNNING,
            'reserved_at' => date('Y-m-d H:i:s', time() - 3600),
        ], ['id' => $id]);

        assertSame(1, Queue::releaseStuck(15), 'воркер могли убить посреди работы');

        Connection::instance()->update('jobs', [
            'status'      => Queue::DONE,
            'finished_at' => date('Y-m-d H:i:s', time() - 30 * 86400),
        ], ['id' => $id]);

        assertSame(1, Queue::purge(7));
    });
});

test('расписание: задача выполняется один раз за окно', function (): void {
    withOwnDatabase(static function (): void {
        Scheduler::reset();

        $runs = 0;

        Scheduler::every(3600, 'test:tick', static function () use (&$runs): void {
            $runs++;
        });

        Scheduler::run();
        Scheduler::run();

        assertSame(1, $runs, 'второй раз в том же окне выполнять нечего');

        Scheduler::reset();
    });
});

test('расписание: задачу, которую уже держит другой процесс, run() не дублирует', function (): void {
    withOwnDatabase(static function (): void {
        Scheduler::reset();

        $runs = 0;

        Scheduler::every(3600, 'test:overlap', static function () use (&$runs): void {
            $runs++;
        });

        // Так выглядит соседний воркер, который уже взялся за эту же задачу
        $foreign = new Lock(null, 'schedule:test:overlap');

        assertTrue($foreign->acquire(0));

        Scheduler::run();

        assertSame(0, $runs, 'блокировка держит чужой процесс — свой прогон пропускает задачу');

        $foreign->release();

        Scheduler::run();

        assertSame(1, $runs, 'блокировка снята — задача выполняется как обычно');

        Scheduler::reset();
    });
});

test('расписание: задача «от конца» ставит отметку только после себя', function (): void {
    withOwnDatabase(static function (): void {
        Scheduler::reset();

        $duringRun = null;

        Scheduler::everyAfterPrevious(3600, 'test:slow', static function () use (&$duringRun): void {
            // Пока задача работает, отметка ещё старая — обычная every() уже
            // выставила бы новую до вызова колбэка
            $duringRun = Setting::get('schedule:test:slow', 'пусто');
        });

        Scheduler::run();

        assertSame('пусто', $duringRun, 'во время работы отметка не тронута');
        assertTrue((int) Setting::get('schedule:test:slow', '0') > 0, 'а после выполнения — стоит');

        Scheduler::reset();
    });
});
