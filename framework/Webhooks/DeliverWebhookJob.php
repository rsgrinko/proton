<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Webhooks;

use Rsgrinko\Proton\Access\Permission;
use Rsgrinko\Proton\Models\Webhook;
use Rsgrinko\Proton\Models\WebhookDelivery;
use Rsgrinko\Proton\Notifications\Notify;
use Rsgrinko\Proton\Notifications\WebhookDisabledNotification;
use Rsgrinko\Proton\Queue\Job;
use Rsgrinko\Proton\Support\Config;
use Rsgrinko\Proton\Support\HttpClient;
use Rsgrinko\Proton\Support\Logger;
use Rsgrinko\Proton\Support\ProtonException;
use Rsgrinko\Proton\Support\RequestId;
use Throwable;

/**
 * Доставка одной посылки подписчику.
 *
 * Тело посылки уже лежит в журнале доставок — задача несёт только её номер:
 * так повтор отправляет ровно то же, что и первая попытка, и подпись сходится.
 *
 * Удачей считается ответ 2xx. Всё остальное — ошибка, а значит повтор с
 * растущей паузой; когда попытки кончились, посылка помечается недоставленной,
 * а подписке добавляется неудача. Подписка, молчащая слишком долго,
 * отключается сама.
 */
final class DeliverWebhookJob extends Job
{
    /** Имена заголовков: подписчик разбирает посылку по ним */
    public const HEADER_EVENT     = 'X-Proton-Event';
    public const HEADER_DELIVERY  = 'X-Proton-Delivery';
    public const HEADER_TIMESTAMP = 'X-Proton-Timestamp';
    public const HEADER_SIGNATURE = 'X-Proton-Signature';

    /**
     * Клиент передаётся только в тестах: очередь создаёт задачу без аргументов.
     */
    public function __construct(private ?HttpClient $client = null)
    {
    }

    public function attempts(): int
    {
        return max(1, (int) Config::get('webhooks.attempts', 5));
    }

    public function queue(): string
    {
        return Webhooks::queue();
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload): void
    {
        $delivery = WebhookDelivery::find((int) ($payload['delivery_id'] ?? 0));

        if ($delivery === null) {
            // Журнал почистили раньше, чем воркер добрался до посылки
            return;
        }

        $webhook = Webhook::find((int) $delivery->raw('webhook_id'));

        if ($webhook === null) {
            $delivery->finish(false, 0, '', 'Подписка удалена', 0, (int) $delivery->raw('attempts'));

            return;
        }

        $body      = (string) $delivery->raw('payload');
        $timestamp = time();
        $attempt   = (int) $delivery->raw('attempts') + 1;

        try {
            $response = $this->client()->post((string) $webhook->raw('url'), $body, [
                'Content-Type: application/json',
                'User-Agent: Proton-Webhook/1.0',
                self::HEADER_EVENT . ': ' . (string) $delivery->raw('event'),
                self::HEADER_DELIVERY . ': ' . $delivery->id(),
                self::HEADER_TIMESTAMP . ': ' . $timestamp,
                self::HEADER_SIGNATURE . ': ' . $webhook->sign($body, $timestamp),
                RequestId::HEADER . ': ' . RequestId::current(),
            ]);
        } catch (Throwable $e) {
            $delivery->finish(false, 0, '', $e->getMessage(), 0, $attempt);
            $webhook->markAttempt(0, $e->getMessage());

            // Исключение наружу: очередь сама решит, повторять или хватит
            throw $e;
        }

        $ok = $response['status'] >= 200 && $response['status'] < 300;

        $delivery->finish(
            $ok,
            $response['status'],
            $response['body'],
            $ok ? '' : 'Подписчик ответил ' . $response['status'],
            $response['duration'],
            $attempt
        );

        if (!$ok) {
            $webhook->markAttempt($response['status'], 'Подписчик ответил ' . $response['status']);

            throw new ProtonException('Подписчик ответил ' . $response['status']);
        }

        $webhook->markDelivered($response['status']);
    }

    /**
     * Попытки кончились: отмечаем неудачу у подписки и, если она молчит
     * слишком давно, отключаем — возить посылки в никуда незачем.
     *
     * @param array<string, mixed> $payload
     */
    public function failed(array $payload, string $error): void
    {
        $delivery = WebhookDelivery::find((int) ($payload['delivery_id'] ?? 0));

        if ($delivery === null) {
            return;
        }

        $webhook = Webhook::find((int) $delivery->raw('webhook_id'));

        if ($webhook === null) {
            return;
        }

        $disabled = $webhook->markFailed(
            (int) $delivery->raw('response_status'),
            $error,
            (int) Config::get('webhooks.disable_after', 0)
        );

        (new Logger('webhooks'))->warning('Посылка не доставлена', [
            'webhook'  => $webhook->id(),
            'delivery' => $delivery->id(),
            'event'    => (string) $delivery->raw('event'),
            'error'    => $error,
            'disabled' => $disabled,
        ]);

        // Отключённая подписка — это тихо потерянные события: пусть те, кто
        // отвечает за вебхуки, узнают об этом сразу
        if ($disabled) {
            Notify::toPermission(
                Permission::WEBHOOKS_MANAGE,
                new WebhookDisabledNotification($webhook, $error)
            );
        }
    }

    private function client(): HttpClient
    {
        return $this->client ??= new HttpClient((int) Config::get('webhooks.timeout', 10));
    }
}
