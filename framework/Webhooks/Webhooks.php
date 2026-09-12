<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Webhooks;

use JsonSerializable;
use Rsgrinko\Proton\Database\Connection;
use Rsgrinko\Proton\Database\Model\Model;
use Rsgrinko\Proton\Events\Events;
use Rsgrinko\Proton\Models\Webhook;
use Rsgrinko\Proton\Models\WebhookDelivery;
use Rsgrinko\Proton\Queue\Queue;
use Rsgrinko\Proton\Support\Config;
use Rsgrinko\Proton\Support\Logger;

/**
 * Вебхуки: событие внутри приложения уходит наружу подписчику.
 *
 *     Webhooks::dispatch('note.created', ['note' => $note]);
 *
 * Отправкой занимается очередь: страница не ждёт чужой сервер, а упавшую
 * посылку воркер повторит. Каждая посылка подписана секретом подписки, и на
 * каждую есть строка в журнале доставок.
 *
 * Подписаться можно только на событие из реестра: события живут в коде, как
 * и права, и заводить под них миграцию незачем. Свои события приложение
 * добавляет в config/webhooks.php:
 *
 *     Webhooks::register('note.created', 'Заметка создана', 'Заметки');
 *
 * Повесить реестр на шину событий — одна строка в config/events.php:
 * Webhooks::subscribe(). После неё каждое объявленное событие из реестра
 * само уезжает подписчикам.
 */
final class Webhooks
{
    /** Пробная посылка: её отправляет кнопка «Проверить» в панели */
    public const TEST_EVENT = 'webhook.test';

    /**
     * События ядра, на которые можно подписаться.
     *
     * @var array<string, array<string, string>>
     */
    private const CORE = [
        'Пользователи' => [
            'user.registered' => 'Завели пользователя',
            'user.login'      => 'Вошёл в приложение',
            'user.logout'     => 'Вышел из приложения',
        ],
        'Служебные' => [
            self::TEST_EVENT => 'Пробная посылка',
        ],
    ];

    /** @var array<string, array<string, string>>|null Реестр: раздел => [событие => подпись] */
    private static ?array $groups = null;

    private static bool $subscribed = false;

    /**
     * Добавить своё событие в реестр.
     */
    public static function register(string $event, string $label, string $group = 'Приложение'): void
    {
        self::boot();

        self::$groups[$group][$event] = $label;
    }

    /**
     * Реестр по разделам — в этом порядке события показываются в форме подписки.
     *
     * @return array<string, array<string, string>>
     */
    public static function groups(): array
    {
        self::boot();

        return self::$groups;
    }

    /**
     * Все события реестра одним списком.
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        $events = [];

        foreach (self::groups() as $group) {
            foreach (array_keys($group) as $event) {
                $events[] = $event;
            }
        }

        return $events;
    }

    public static function known(string $event): bool
    {
        return in_array($event, self::all(), true);
    }

    /**
     * Подпись события. Неизвестное показываем как есть — так видно событие
     * от снятого раздела.
     */
    public static function label(string $event): string
    {
        foreach (self::groups() as $group) {
            if (isset($group[$event])) {
                return $group[$event];
            }
        }

        return $event;
    }

    /**
     * Оставляет только известные события, без повторов и в порядке реестра.
     * Звёздочка («все события») проходит как есть.
     *
     * @param array<int, mixed> $events
     *
     * @return array<int, string>
     */
    public static function filter(array $events): array
    {
        $events = array_map(static fn (mixed $event): string => (string) $event, $events);

        if (in_array(Webhook::ALL_EVENTS, $events, true)) {
            return [Webhook::ALL_EVENTS];
        }

        return array_values(array_filter(
            self::all(),
            static fn (string $event): bool => in_array($event, $events, true)
        ));
    }

    /**
     * Подписывает шину событий на отправку вебхуков: каждое событие реестра,
     * объявленное через Events::fire(), уедет подписчикам.
     */
    public static function subscribe(): void
    {
        if (self::$subscribed) {
            return;
        }

        self::$subscribed = true;

        foreach (self::all() as $event) {
            if ($event === self::TEST_EVENT) {
                // Пробную посылку отправляет панель, а не шина
                continue;
            }

            Events::listen($event, static function (array $payload) use ($event): void {
                self::dispatch($event, $payload);
            });
        }
    }

    /**
     * Рассылает событие подписчикам. Возвращает номера созданных посылок.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<int, int>
     */
    public static function dispatch(string $event, array $payload = []): array
    {
        if (!self::enabled()) {
            return [];
        }

        $webhooks = Webhook::listeningTo($event);

        if ($webhooks === []) {
            return [];
        }

        $body = self::body($event, $payload);
        $ids  = [];

        foreach ($webhooks as $webhook) {
            $delivery = WebhookDelivery::start($webhook, $event, $body);

            Queue::push(DeliverWebhookJob::class, ['delivery_id' => $delivery->id()], 0, self::queue());

            $ids[] = $delivery->id();
        }

        (new Logger('webhooks'))->info('Событие ушло подписчикам', [
            'event'      => $event,
            'deliveries' => count($ids),
        ]);

        return $ids;
    }

    /**
     * Пробная посылка одной подписке — кнопка «Проверить» и консольная команда.
     */
    public static function test(Webhook $webhook): WebhookDelivery
    {
        $body = self::body(self::TEST_EVENT, [
            'message' => 'Проверка подписки «' . (string) $webhook->name . '»',
        ]);

        $delivery = WebhookDelivery::start($webhook, self::TEST_EVENT, $body);

        Queue::push(DeliverWebhookJob::class, ['delivery_id' => $delivery->id()], 0, self::queue());

        return $delivery;
    }

    /**
     * Тело посылки: событие, время, данные. Ровно это и подписывается.
     *
     * @param array<string, mixed> $payload
     */
    public static function body(string $event, array $payload): string
    {
        return (string) json_encode([
            'event'     => $event,
            'timestamp' => time(),
            'sent_at'   => Connection::now(),
            'app'       => (string) Config::get('app.name', 'Proton'),
            'data'      => self::normalize($payload),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Приводит данные события к тому, что переживёт json_encode: модели
     * отдаются массивом (скрытые поля в нём уже вырезаны), посторонние
     * объекты выбрасываются — секретам и ресурсам наружу делать нечего.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public static function normalize(array $payload): array
    {
        $result = [];

        foreach ($payload as $key => $value) {
            $clean = self::value($value);

            // Объект, который нечем показать, в посылку не попадает вовсе
            if ($clean !== null || $value === null) {
                $result[(string) $key] = $clean;
            }
        }

        return $result;
    }

    /**
     * Очередь для посылок. Своя очередь позволяет разбирать их отдельным
     * воркером: чужой сервер отвечает долго, а письма ждать не должны.
     */
    public static function queue(): string
    {
        $queue = (string) Config::get('webhooks.queue', Queue::DEFAULT_QUEUE);

        return $queue !== '' ? $queue : Queue::DEFAULT_QUEUE;
    }

    /**
     * Включены ли вебхуки вообще. Таблиц нет (база старее этого кода) — молча
     * ничего не делаем: событие не должно ронять то, что его объявило.
     */
    public static function enabled(): bool
    {
        if (!(bool) Config::get('webhooks.enabled', true)) {
            return false;
        }

        return Connection::instance()->hasTable('webhooks');
    }

    /**
     * Сбрасывает реестр и подписку — нужно тестам.
     */
    public static function reset(): void
    {
        self::$groups     = null;
        self::$subscribed = false;
    }

    /**
     * Одно значение события для посылки.
     */
    private static function value(mixed $value): mixed
    {
        if ($value instanceof Model) {
            return $value->toArray();
        }

        if ($value instanceof JsonSerializable) {
            return $value->jsonSerialize();
        }

        if (is_array($value)) {
            return self::normalize($value);
        }

        if (is_scalar($value) || $value === null) {
            return $value;
        }

        // Объект неизвестной породы наружу не отдаём
        return null;
    }

    private static function boot(): void
    {
        if (self::$groups !== null) {
            return;
        }

        self::$groups = self::CORE;

        $file = APP_ROOT . '/config/webhooks.php';

        if (is_file($file)) {
            require $file;
        }
    }
}
