<?php

declare(strict_types=1);

/**
 * Вебхуки: реестр событий, подписки, подпись посылки, доставка и повторы.
 *
 * Настоящий сервер тут не нужен: доставку проверяем подменным HTTP-клиентом,
 * а у него спрашиваем, что именно уехало подписчику.
 */

use Rsgrinko\Proton\Models\Webhook;
use Rsgrinko\Proton\Models\WebhookDelivery;
use Rsgrinko\Proton\Queue\Queue;
use Rsgrinko\Proton\Queue\Worker;
use Rsgrinko\Proton\Support\HttpClient;
use Rsgrinko\Proton\Webhooks\DeliverWebhookJob;
use Rsgrinko\Proton\Webhooks\Webhooks;

test('вебхуки: реестр знает события ядра и приложения', function (): void {
    assertTrue(Webhooks::known('user.registered'), 'событие ядра');
    assertTrue(Webhooks::known('note.created'), 'событие приложения из config/webhooks.php');
    assertFalse(Webhooks::known('нет.такого'));

    assertSame('Завели пользователя', Webhooks::label('user.registered'));
    // Незнакомое событие показываем как есть
    assertSame('нет.такого', Webhooks::label('нет.такого'));
});

test('вебхуки: лишние события в подписку не попадают', function (): void {
    assertSame(['user.login'], Webhooks::filter(['user.login', 'выдуманное']));

    // Звёздочка перебивает всё: подписка на «все события»
    assertSame([Webhook::ALL_EVENTS], Webhooks::filter(['user.login', Webhook::ALL_EVENTS]));
});

test('вебхуки: подписка понимает, какие события ей нужны', function (): void {
    withOwnDatabase(static function (): void {
        $one = Webhook::add('Только вход', 'https://example.com/hook', ['user.login']);
        $all = Webhook::add('Всё подряд', 'https://example.com/all', [Webhook::ALL_EVENTS]);

        assertTrue($one->wants('user.login'));
        assertFalse($one->wants('user.logout'));
        assertTrue($all->wants('user.logout'), 'звёздочка берёт любое событие');

        assertCount(2, Webhook::listeningTo('user.login'));
        assertCount(1, Webhook::listeningTo('user.logout'));

        // Отключённая подписка не получает ничего
        $all->forceFill(['active' => 0])->save();

        assertCount(1, Webhook::listeningTo('user.login'));
    });
});

test('вебхуки: секрет хранится шифрованным и подпись сходится', function (): void {
    withOwnDatabase(static function (): void {
        $webhook = Webhook::add('Подпись', 'https://example.com/hook', [Webhook::ALL_EVENTS], 'секрет-подписки');

        assertSame('секрет-подписки', $webhook->secret());
        assertContains('enc:v1:', (string) $webhook->raw('secret'), 'в базе лежит шифротекст');

        $signature = $webhook->sign('{"a":1}', 1700000000);

        assertSame('sha256=' . hash_hmac('sha256', '1700000000.{"a":1}', 'секрет-подписки'), $signature);

        // Секрет в выдачу модели не попадает
        assertFalse(array_key_exists('secret', $webhook->toArray()), 'секрет скрыт');

        $fresh = $webhook->rotateSecret();

        assertTrue($fresh !== 'секрет-подписки');
        assertSame($fresh, Webhook::find($webhook->id())?->secret());
    });
});

test('вебхуки: событие превращается в посылки и задачи очереди', function (): void {
    withOwnDatabase(static function (): void {
        Webhook::add('Первый', 'https://example.com/one', ['user.login']);
        Webhook::add('Второй', 'https://example.com/two', [Webhook::ALL_EVENTS]);
        Webhook::add('Чужое событие', 'https://example.com/three', ['user.logout']);

        $ids = Webhooks::dispatch('user.login', ['login' => 'petrov']);

        assertCount(2, $ids, 'посылки ушли только тем, кто ждал событие');
        assertSame(2, WebhookDelivery::query()->where('status', WebhookDelivery::PENDING)->count());

        $delivery = assertNotNull(WebhookDelivery::find($ids[0]));
        $body     = json_decode((string) $delivery->raw('payload'), true);

        assertSame('user.login', $body['event']);
        assertSame('petrov', $body['data']['login']);

        // Задачи легли в свою очередь
        assertSame(2, Queue::stats()[Queue::QUEUED] ?? 0);
    });
});

test('вебхуки: в посылку не уходят посторонние объекты', function (): void {
    withOwnDatabase(static function (): void {
        $user = Rsgrinko\Proton\Models\User::register('webhook_payload', 'секрет123', ['email' => 'wh@example.com']);

        $body = json_decode(Webhooks::body('user.registered', [
            'user'   => $user,
            'поток'  => fopen('php://memory', 'rb'),
            'вложен' => ['число' => 5],
        ]), true);

        assertSame('webhook_payload', $body['data']['user']['login']);
        assertFalse(array_key_exists('password_hash', $body['data']['user']), 'хеш пароля наружу не уходит');
        assertFalse(array_key_exists('поток', $body['data']), 'ресурс в посылку не попал');
        assertSame(5, $body['data']['вложен']['число']);
    });
});

test('вебхуки: доставка помечает посылку и снимает неудачи подписки', function (): void {
    withOwnDatabase(static function (): void {
        $webhook = Webhook::add('Приёмник', 'https://example.com/hook', [Webhook::ALL_EVENTS], 'секрет');

        $webhook->forceFill(['failures' => 3])->save();

        $delivery = Webhooks::test($webhook);

        webhookDeliver($delivery->id(), 200, '{"ok":true}');

        $delivery = assertNotNull(WebhookDelivery::find($delivery->id()));

        assertSame(WebhookDelivery::SENT, (string) $delivery->raw('status'));
        assertSame(200, (int) $delivery->raw('response_status'));
        assertSame(1, (int) $delivery->raw('attempts'));

        $webhook = assertNotNull(Webhook::find($webhook->id()));

        assertSame(0, (int) $webhook->raw('failures'), 'удачная посылка обнуляет счётчик');
        assertSame(200, (int) $webhook->raw('last_status'));
    });
});

test('вебхуки: посылка подписана и несёт свои заголовки', function (): void {
    withOwnDatabase(static function (): void {
        $webhook  = Webhook::add('Приёмник', 'https://example.com/hook', [Webhook::ALL_EVENTS], 'секрет');
        $delivery = Webhooks::test($webhook);

        $sent = webhookDeliver($delivery->id(), 200, 'ok');

        $headers = [];

        foreach ($sent->headers as $header) {
            [$name, $value] = explode(': ', $header, 2);

            $headers[$name] = $value;
        }

        assertSame(Webhooks::TEST_EVENT, $headers[DeliverWebhookJob::HEADER_EVENT]);
        assertSame((string) $delivery->id(), $headers[DeliverWebhookJob::HEADER_DELIVERY]);

        $timestamp = (int) $headers[DeliverWebhookJob::HEADER_TIMESTAMP];

        assertSame(
            $webhook->sign($sent->body, $timestamp),
            $headers[DeliverWebhookJob::HEADER_SIGNATURE],
            'подпись считается от времени и тела посылки'
        );

        assertSame('https://example.com/hook', $sent->url);
    });
});

test('вебхуки: чужая ошибка возвращает посылку в очередь, а потом хватит', function (): void {
    withOwnDatabase(static function (): void {
        withConfig(['webhooks.attempts' => 2, 'webhooks.disable_after' => 2], static function (): void {
            $webhook  = Webhook::add('Падучий', 'https://example.com/fail', [Webhook::ALL_EVENTS]);
            $delivery = Webhooks::test($webhook);

            // Первая попытка: подписчик ответил пятисоткой
            $job = assertNotNull(Queue::claim(Webhooks::queue()));

            assertThrows(static function () use ($delivery): void {
                webhookDeliver($delivery->id(), 500, 'всё плохо');
            });

            assertTrue(Queue::fail($job, 'Подписчик ответил 500', 60), 'попытка ещё есть');

            $failed = assertNotNull(WebhookDelivery::find($delivery->id()));

            assertSame(WebhookDelivery::FAILED, (string) $failed->raw('status'));
            assertSame(500, (int) $failed->raw('response_status'));

            // Подписка уже знает, что подписчик отвечает не так
            assertSame(500, (int) assertNotNull(Webhook::find($webhook->id()))->raw('last_status'));

            // Вторая попытка — последняя
            $job['attempts'] = 2;

            assertFalse(Queue::fail($job, 'Подписчик ответил 500', 60), 'попытки кончились');

            (new DeliverWebhookJob())->failed(['delivery_id' => $delivery->id()], 'Подписчик ответил 500');

            $webhook = assertNotNull(Webhook::find($webhook->id()));

            assertSame(1, (int) $webhook->raw('failures'));
            assertSame(500, (int) $webhook->raw('last_status'));
        });
    });
});

test('вебхуки: молчащая подписка отключается сама', function (): void {
    withOwnDatabase(static function (): void {
        $webhook = Webhook::add('Молчун', 'https://example.com/silent', [Webhook::ALL_EVENTS]);

        $webhook->forceFill(['failures' => 2])->save();

        assertTrue($webhook->markFailed(0, 'нет ответа', 3), 'третья неудача подряд выключает подписку');
        assertFalse((bool) assertNotNull(Webhook::find($webhook->id()))->active);
    });
});

test('вебхуки: удалённая подписка не держит очередь', function (): void {
    withOwnDatabase(static function (): void {
        $webhook  = Webhook::add('Уйдёт', 'https://example.com/gone', [Webhook::ALL_EVENTS]);
        $delivery = Webhooks::test($webhook);

        $webhook->delete();

        // Задача не падает, а закрывает посылку: повторять нечего
        (new DeliverWebhookJob())->handle(['delivery_id' => $delivery->id()]);

        $delivery = assertNotNull(WebhookDelivery::find($delivery->id()));

        assertSame(WebhookDelivery::FAILED, (string) $delivery->raw('status'));
        assertContains('Подписка удалена', (string) $delivery->raw('error'));
    });
});

test('вебхуки: воркер разбирает и свою очередь, и общую', function (): void {
    assertSame(['default'], Worker::parseQueues(''));
    assertSame(['default', 'webhooks'], Worker::parseQueues('default, webhooks'));
    assertSame(['webhooks'], Worker::parseQueues('webhooks,webhooks'));
});

test('вебхуки: старые посылки подчищаются', function (): void {
    withOwnDatabase(static function (): void {
        $webhook = Webhook::add('Для уборки', 'https://example.com/hook', [Webhook::ALL_EVENTS]);

        $old = WebhookDelivery::start($webhook, Webhooks::TEST_EVENT, '{}');

        $old->forceFill(['created_at' => date('Y-m-d H:i:s', time() - 40 * 86400)])->save();

        $fresh = WebhookDelivery::start($webhook, Webhooks::TEST_EVENT, '{}');

        assertSame(1, WebhookDelivery::purge(14));
        assertNull(WebhookDelivery::find($old->id()));
        assertNotNull(WebhookDelivery::find($fresh->id()));
    });
});

/**
 * Клиент-заглушка: в сеть не ходит, отвечает заданным кодом и запоминает,
 * что у него просили отправить.
 */
final class WebhookTestClient extends HttpClient
{
    public string $body = '';

    /** @var array<int, string> */
    public array $headers = [];

    public string $url = '';

    public function __construct(private int $status, private string $response)
    {
        parent::__construct(5);
    }

    /**
     * @param array<int, string> $headers
     *
     * @return array{status: int, body: string, duration: int}
     */
    public function post(string $url, string $body, array $headers = []): array
    {
        $this->url     = $url;
        $this->body    = $body;
        $this->headers = $headers;

        return ['status' => $this->status, 'body' => $this->response, 'duration' => 7];
    }
}

/**
 * Выполняет доставку подменным клиентом и возвращает то, что уехало бы наружу.
 * Ошибку подписчика задача бросает дальше — очередь решает, повторять ли.
 */
function webhookDeliver(int $deliveryId, int $status, string $response): WebhookTestClient
{
    $client = new WebhookTestClient($status, $response);

    (new DeliverWebhookJob($client))->handle(['delivery_id' => $deliveryId]);

    return $client;
}
