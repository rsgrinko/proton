<?php

declare(strict_types=1);

/**
 * Приоритеты и мёртвые задачи, ручной запуск расписания, входящие вебхуки.
 */

use Rsgrinko\Proton\Database\Connection;
use Rsgrinko\Proton\Database\Model\Factory;
use Rsgrinko\Proton\Http\Request;
use Rsgrinko\Proton\Models\IncomingHook;
use Rsgrinko\Proton\Models\Role;
use Rsgrinko\Proton\Models\Setting;
use Rsgrinko\Proton\Models\User;
use Rsgrinko\Proton\Queue\Queue;
use Rsgrinko\Proton\Queue\Scheduler;
use Rsgrinko\Proton\Webhooks\Incoming;

test('очередь: важная задача разбирается раньше обычной', function (): void {
    withOwnDatabase(static function (): void {
        Queue::push(QueueTestJob::class, ['mark' => 'обычная']);
        Queue::push(QueueTestJob::class, ['mark' => 'важная'], 0, null, 10);

        $claimed = Queue::claim();

        assertNotNull($claimed);
        assertContains('важная', (string) $claimed['payload'], 'первой берётся та, у которой приоритет выше');
    });
});

test('очередь: пауза перед повтором разная у соседних задач', function (): void {
    $job = new QueueTestJob();

    $pauses = [];

    for ($number = 0; $number < 12; $number++) {
        $pauses[] = $job->backoff(2);
    }

    assertTrue(count(array_unique($pauses)) > 1, 'джиттер разводит повторы во времени');

    foreach ($pauses as $pause) {
        assertTrue($pause >= 60 && $pause <= 90, 'но остаётся в разумных пределах: ' . $pause);
    }
});

test('очередь: мёртвую задачу возвращают в работу из панели', function (): void {
    withOwnDatabase(static function (): void {
        /** @var User $admin */
        $admin = Factory::of(User::class)->create(['role_id' => Role::admin()?->id() ?? 0]);

        $id = Queue::push(QueueTestJob::class, ['mark' => 'мёртвая']);

        Connection::instance()->update('jobs', [
            'status'   => Queue::DEAD,
            'attempts' => 3,
            'error'    => 'чужой сервис лежал',
        ], ['id' => $id]);

        actingAs($admin);

        $page = get('/admin/queue', ['status' => Queue::DEAD]);

        assertStatus(200, $page);
        assertSee('чужой сервис лежал', $page);

        assertRedirect('/admin/queue', post('/admin/queue/retry', ['ids' => [$id]]));

        $row = Connection::instance()->selectOne('SELECT * FROM jobs WHERE id = :id', ['id' => $id]);

        assertSame(Queue::QUEUED, (string) $row['status'], 'вернулась в очередь');
        assertSame(0, (int) $row['attempts'], 'попытки обнулены');

        // И удаление
        post('/admin/queue/delete', ['ids' => [$id]]);

        assertNull(Connection::instance()->selectOne('SELECT * FROM jobs WHERE id = :id', ['id' => $id]));

        actingAs(null);
    });
});

test('расписание: задачу запускают кнопкой, неизвестную — нет', function (): void {
    withOwnDatabase(static function (): void {
        Scheduler::reset();

        $done = false;

        Scheduler::every(3600, 'test:manual', static function () use (&$done): void {
            $done = true;
        });

        assertTrue(Scheduler::runNow('test:manual') === true);
        assertTrue($done, 'задача выполнилась');
        assertTrue(Setting::get('schedule:test:manual', '') !== '', 'отметка о запуске поставлена');

        assertNull(Scheduler::runNow('нет-такой'));

        Scheduler::reset();
    });
});

test('входящие вебхуки: без подписи не принимаем, с подписью — принимаем', function (): void {
    withOwnDatabase(static function (): void {
        Incoming::reset();

        $received = [];

        Incoming::register('test', 'Проверка', 'TEST_HOOK_SECRET', static function (array $payload) use (&$received): void {
            $received = $payload;
        });

        putenv('TEST_HOOK_SECRET=секрет-источника');

        // Без заголовков подпись не сходится
        assertSame(IncomingHook::REJECTED, Incoming::receive('test', Request::create('POST', '/api/v1/hooks/test', ['event' => 'ping'])));

        // Неизвестный источник
        assertSame(IncomingHook::UNKNOWN, Incoming::receive('нет-такого', Request::create('POST', '/api/v1/hooks/x')));

        // С правильной подписью — принимаем. Подпись считается от тела запроса,
        // поэтому сначала собираем запрос, потом подписываем его тело
        $timestamp = time();
        $request   = Request::create('POST', '/api/v1/hooks/test', ['event' => 'ping', 'id' => '7']);
        $signature = hash_hmac('sha256', $timestamp . '.' . $request->rawBody, 'секрет-источника');

        $request = Request::create('POST', '/api/v1/hooks/test', ['event' => 'ping', 'id' => '7'], [], [
            'x-proton-signature' => $signature,
            'x-proton-timestamp' => (string) $timestamp,
        ]);

        assertSame(IncomingHook::DONE, Incoming::receive('test', $request));
        assertSame('ping', $received['event'] ?? '');

        // Всё записано в журнал
        assertSame(3, IncomingHook::query()->count());
        assertSame(1, IncomingHook::query()->where('status', IncomingHook::DONE)->count());

        putenv('TEST_HOOK_SECRET');
        Incoming::reset();
    });
});

test('входящие вебхуки: старая посылка не принимается', function (): void {
    withOwnDatabase(static function (): void {
        Incoming::reset();

        Incoming::register('test', 'Проверка', 'TEST_HOOK_SECRET', static function (): void {
        });

        putenv('TEST_HOOK_SECRET=секрет-источника');

        $timestamp = time() - 3600;
        $signature = hash_hmac('sha256', $timestamp . '.' . '{}', 'секрет-источника');

        $request = Request::create('POST', '/api/v1/hooks/test', ['event' => 'ping'], [], [
            'x-proton-signature' => $signature,
            'x-proton-timestamp' => (string) $timestamp,
        ]);

        assertSame(IncomingHook::REJECTED, Incoming::receive('test', $request), 'подслушанную посылку не повторить');

        putenv('TEST_HOOK_SECRET');
        Incoming::reset();
    });
});

test('входящие вебхуки: упавший обработчик не роняет приём', function (): void {
    withOwnDatabase(static function (): void {
        Incoming::reset();

        Incoming::register('test', 'Проверка', '', static function (): void {
            throw new RuntimeException('обработчик сломался');
        });

        $status = Incoming::receive('test', Request::create('POST', '/api/v1/hooks/test', ['event' => 'ping']));

        assertSame(IncomingHook::FAILED, $status);

        $hook = assertNotNull(IncomingHook::query()->orderBy('id', 'desc')->first());

        assertContains('обработчик сломался', (string) $hook->raw('error'));

        Incoming::reset();
    });
});
