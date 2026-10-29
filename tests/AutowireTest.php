<?php

declare(strict_types=1);

/**
 * Зависимости в конструкторе задач очереди и консольных команд: их подставляет
 * контейнер, а постановка в очередь и справка обходятся без сборки.
 */

use Rsgrinko\Proton\Console\Application;
use Rsgrinko\Proton\Console\Command;
use Rsgrinko\Proton\Database\Connection;
use Rsgrinko\Proton\Models\Setting;
use Rsgrinko\Proton\Queue\Job;
use Rsgrinko\Proton\Queue\Queue;
use Rsgrinko\Proton\Queue\Worker;
use Rsgrinko\Proton\Support\Container;

interface AutowireTestClock
{
    public function now(): string;
}

final class AutowireTestFrozenClock implements AutowireTestClock
{
    public function now(): string
    {
        return '2026-01-01 00:00:00';
    }
}

/**
 * Задача с зависимостью по интерфейсу — как её напишет приложение.
 */
final class AutowireTestJob extends Job
{
    public function __construct(private readonly AutowireTestClock $clock)
    {
    }

    public function queue(): string
    {
        return 'часы';
    }

    public function attempts(): int
    {
        return 1;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload): void
    {
        Setting::set('test:autowire:job', $this->clock->now());
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function failed(array $payload, string $error): void
    {
        Setting::set('test:autowire:failed', $this->clock->now());
    }
}

final class AutowireTestCommand extends Command
{
    public function __construct(private readonly AutowireTestClock $clock)
    {
        parent::__construct();
    }

    public function name(): string
    {
        return 'test:clock';
    }

    public function description(): string
    {
        return 'Печатает время из зависимости';
    }

    public function run(): int
    {
        $this->line($this->clock->now());

        return 0;
    }
}

/**
 * Свой контейнер с привязкой часов на время теста.
 */
function withClockBinding(callable $body): void
{
    $previous  = Container::instance();
    $container = new Container();
    $container->bind(AutowireTestClock::class, AutowireTestFrozenClock::class);

    Container::setInstance($container);

    try {
        $body();
    } finally {
        Container::setInstance($previous);
    }
}

test('автовайринг: задача ставится в очередь без сборки зависимостей', function (): void {
    withOwnDatabase(static function (): void {
        // Привязки нет вовсе: собрать задачу нельзя, а поставить — можно
        $id  = Queue::push(AutowireTestJob::class);
        $row = assertNotNull(Connection::instance()->selectOne('SELECT * FROM jobs WHERE id = :id', ['id' => $id]));

        assertSame('часы', (string) $row['queue'], 'очередь взята у класса');
        assertSame(1, (int) $row['max_attempts']);
    });
});

test('автовайринг: воркер собирает задачу контейнером', function (): void {
    withOwnDatabase(static function (): void {
        withClockBinding(static function (): void {
            Queue::push(AutowireTestJob::class);

            $result = (new Worker('часы'))->run(true);

            assertSame(1, $result['done']);
            assertSame('2026-01-01 00:00:00', Setting::get('test:autowire:job'));
        });
    });
});

test('автовайринг: несобравшаяся задача падает как обычная, а не роняет воркер', function (): void {
    withOwnDatabase(static function (): void {
        $id = Queue::push(AutowireTestJob::class);

        $result = (new Worker('часы'))->run(true);

        assertSame(1, $result['failed']);

        $row = assertNotNull(Connection::instance()->selectOne('SELECT * FROM jobs WHERE id = :id', ['id' => $id]));

        assertContains('не собралась', (string) $row['error']);
        assertSame(Queue::DEAD, (string) $row['status'], 'попытка у задачи одна — она кончилась');
    });
});

test('автовайринг: зависшая задача зовёт failed() уже собранной', function (): void {
    withOwnDatabase(static function (): void {
        withClockBinding(static function (): void {
            $id = Queue::push(AutowireTestJob::class);

            // Воркер «пропал» посреди единственной попытки
            Connection::instance()->update('jobs', [
                'status'      => Queue::RUNNING,
                'attempts'    => 1,
                'reserved_at' => date('Y-m-d H:i:s', time() - 7200),
            ], ['id' => $id]);

            assertSame(1, Queue::releaseStuck(15));
            assertSame('2026-01-01 00:00:00', Setting::get('test:autowire:failed'));
        });
    });
});

test('автовайринг: команда собирается контейнером, справке сборка не нужна', function (): void {
    // Без привязки команду не собрать, а имя и описание узнать можно
    $described = Application::describe(AutowireTestCommand::class);

    assertSame('test:clock', $described->name());
    assertSame('Печатает время из зависимости', $described->description());

    withClockBinding(static function (): void {
        $lines = [];

        $status = Container::instance()->make(AutowireTestCommand::class)
            ->withInput([], [], static function (string $line) use (&$lines): void {
                $lines[] = $line;
            })
            ->run();

        assertSame(0, $status);
        assertSame(['2026-01-01 00:00:00'], $lines);
    });
});

test('автовайринг: справка и поиск команды по-прежнему работают', function (): void {
    ob_start();

    try {
        $status = (new Application())->run(['proton', 'help']);
    } finally {
        $help = (string) ob_get_clean();
    }

    assertSame(0, $status);
    assertContains('queue:status', $help);

    ob_start();

    try {
        $status = (new Application())->run(['proton', 'core:version']);
    } finally {
        $output = (string) ob_get_clean();
    }

    assertSame(0, $status);
    assertContains(trim((string) file_get_contents(APP_ROOT . '/framework/VERSION')), $output);
});
