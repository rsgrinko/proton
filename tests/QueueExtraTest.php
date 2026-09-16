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
use Rsgrinko\Proton\Queue\Worker;
use Rsgrinko\Proton\Support\Env;
use Rsgrinko\Proton\Support\Profiler;
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

test('здоровье: мёртвая задача переводит очередь в warn, а не в fail', function (): void {
    withOwnDatabase(static function (): void {
        $id = Queue::push(QueueTestJob::class);

        Connection::instance()->update('jobs', ['status' => Queue::DEAD], ['id' => $id]);

        $body = (string) (new App\Controllers\Api\SystemController())->health()->body();
        $data = json_decode($body, true);

        assertSame('warn', $data['checks']['queue']['status'], 'мёртвая задача — повод присмотреться, не авария');
        assertSame(1, $data['checks']['queue']['failed']);
        assertSame('warn', $data['status'], 'худшая из проверок поднимается наверх');
        assertSame('ok', $data['checks']['database']['status'], 'база рядом не пострадала');
    });
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

test('входящие вебхуки: без токена не принимаем, с токеном — принимаем', function (): void {
    withOwnDatabase(static function (): void {
        Incoming::reset();

        $received = [];

        Incoming::register('test', 'Проверка', 'TEST_HOOK_TOKEN', static function (array $payload) use (&$received): void {
            $received = $payload;
        });

        putenv('TEST_HOOK_TOKEN=токен-источника');

        // Без токена не пускаем
        assertSame(
            IncomingHook::REJECTED,
            Incoming::receive('test', Request::create('POST', '/api/v1/hooks/test', ['event' => 'ping']))
        );

        // Неизвестный источник
        assertSame(IncomingHook::UNKNOWN, Incoming::receive('нет-такого', Request::create('POST', '/api/v1/hooks/x')));

        // Токен заголовком
        $request = Request::create('POST', '/api/v1/hooks/test', ['event' => 'ping', 'id' => '7'], [], [
            'x-proton-token' => 'токен-источника',
        ]);

        assertSame(IncomingHook::DONE, Incoming::receive('test', $request));
        assertSame('ping', $received['event'] ?? '');

        // Токен параметром — так шлют GET-посылки
        $get = Request::create('GET', '/api/v1/hooks/test', [], ['event' => 'ping', 'token' => 'токен-источника']);

        assertSame(IncomingHook::DONE, Incoming::receive('test', $get));

        // Чужой токен не подходит
        $foreign = Request::create('POST', '/api/v1/hooks/test', ['event' => 'ping'], [], [
            'x-proton-token' => 'не-тот',
        ]);

        assertSame(IncomingHook::REJECTED, Incoming::receive('test', $foreign));

        // Всё записано в журнал, и токен в нём не осел
        assertSame(5, IncomingHook::query()->count());
        assertSame(2, IncomingHook::query()->where('status', IncomingHook::DONE)->count());

        foreach (IncomingHook::query()->get() as $hook) {
            assertNotContains('токен-источника', (string) json_encode($hook->payload, JSON_UNESCAPED_UNICODE));
        }

        putenv('TEST_HOOK_TOKEN');
        Incoming::reset();
    });
});

test('входящие вебхуки: источник без токена принимает всех', function (): void {
    withOwnDatabase(static function (): void {
        Incoming::reset();

        $seen = false;

        Incoming::register('open', 'Открытый', '', static function () use (&$seen): void {
            $seen = true;
        });

        $request = Request::create('GET', '/api/v1/hooks/open', [], ['event' => 'ping']);

        assertSame(IncomingHook::DONE, Incoming::receive('open', $request));
        assertTrue($seen);

        Incoming::reset();
    });
});

test('входящие вебхуки: недонастроенный источник никого не пускает', function (): void {
    withOwnDatabase(static function (): void {
        Incoming::reset();

        Incoming::register('test', 'Проверка', 'TEST_EMPTY_TOKEN', static function (): void {
        });

        // Переменная объявлена в реестре, но пуста в окружении — это ошибка
        // настройки, а не разрешение пускать всех
        putenv('TEST_EMPTY_TOKEN');

        assertSame(
            IncomingHook::REJECTED,
            Incoming::receive('test', Request::create('POST', '/api/v1/hooks/test', ['event' => 'ping']))
        );

        // И в журнале видно, что дело в нас, а не в отправителе
        $hook = assertNotNull(IncomingHook::query()->orderBy('id', 'desc')->first());

        assertContains('переменная TEST_EMPTY_TOKEN пуста', (string) $hook->raw('error'));

        Incoming::reset();
    });
});

test('входящие вебхуки: токен берётся и из .env, а не только из окружения', function (): void {
    withOwnDatabase(static function (): void {
        Incoming::reset();

        $seen = false;

        Incoming::register('env', 'Из .env', 'TEST_ENV_HOOK_TOKEN', static function () use (&$seen): void {
            $seen = true;
        });

        // В окружении процесса переменной нет — так и бывает, когда токен просто
        // вписали в .env, а не экспортировали в среду
        putenv('TEST_ENV_HOOK_TOKEN');

        $file = APP_ROOT . '/var/incoming-token-test.env';

        file_put_contents($file, "TEST_ENV_HOOK_TOKEN=из-файла\n");
        Env::load($file, true);
        unlink($file);

        $request = Request::create('GET', '/api/v1/hooks/env', [], ['event' => 'ping', 'token' => 'из-файла']);

        assertSame(IncomingHook::DONE, Incoming::receive('env', $request));
        assertTrue($seen);

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

test('очередь: воркер пишет время задачи и число запросов к базе', function (): void {
    withOwnDatabase(static function (): void {
        withConfig(['app.debug' => true], static function (): void {
            Profiler::reset();

            $id = Queue::push(QueueTestJob::class, ['mark' => 'профиль']);

            (new Worker())->run(true);

            $row = assertNotNull(Connection::instance()->selectOne(
                'SELECT * FROM jobs WHERE id = :id',
                ['id' => $id]
            ));

            assertSame(Queue::DONE, (string) $row['status']);
            assertTrue((int) $row['duration_ms'] >= 0, 'время выполнения записано');
            assertTrue((int) $row['queries_count'] >= 1, 'задача пишет настройку — хотя бы один запрос был');
            assertTrue((float) $row['queries_ms'] >= 0);
        });
    });
});

test('очередь: у упавшей задачи время тоже посчитано', function (): void {
    withOwnDatabase(static function (): void {
        Queue::push(QueueFailingJob::class);

        (new Worker())->run(true);

        $row = assertNotNull(Connection::instance()->selectOne('SELECT * FROM jobs LIMIT 1'));

        assertSame(Queue::QUEUED, (string) $row['status'], 'после первой ошибки задача ждёт повтора');
        assertTrue((int) $row['duration_ms'] >= 0, 'время посчитано даже у упавшей задачи');
    });
});
