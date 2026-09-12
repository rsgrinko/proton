<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Console\Commands;

use Rsgrinko\Proton\Console\Command;
use Rsgrinko\Proton\Models\Webhook;
use Rsgrinko\Proton\Models\WebhookDelivery;
use Rsgrinko\Proton\Webhooks\DeliverWebhookJob;
use Rsgrinko\Proton\Webhooks\Webhooks;
use Throwable;

/**
 * пробная посылка подписчику.
 */
final class WebhookTestCommand extends Command
{
    public function name(): string
    {
        return 'webhook:test';
    }

    public function description(): string
    {
        return 'отправить подписке пробную посылку';
    }

    public function usage(): string
    {
        return 'webhook:test <id> [--queue]';
    }

    public function run(): int
    {
        $id = (int) $this->arg(0);

        if ($id <= 0) {
            $this->fail('Укажите номер подписки: webhook:test 1 (список — webhook:list)');

            return 1;
        }

        $webhook = Webhook::find($id);

        if ($webhook === null) {
            $this->fail('Подписка ' . $id . ' не найдена');

            return 1;
        }

        $delivery = Webhooks::test($webhook);

        // По умолчанию отправляем сразу: ждать воркера, чтобы проверить адрес,
        // — лишний шаг. С --queue посылка остаётся в очереди
        if ($this->hasOption('queue')) {
            $this->ok('Посылка №' . $delivery->id() . ' поставлена в очередь «' . Webhooks::queue() . '»');

            return 0;
        }

        try {
            (new DeliverWebhookJob())->handle(['delivery_id' => $delivery->id()]);
        } catch (Throwable) {
            // Ошибку задача уже записала в журнал доставок — печатаем её ниже,
            // вместе с остальным по посылке
        }

        $delivery = WebhookDelivery::find($delivery->id());

        if ($delivery === null) {
            return 1;
        }

        $this->line('Посылка:  №' . $delivery->id());
        $this->line('Адрес:    ' . (string) $webhook->url);
        $this->line('Ответ:    ' . ((int) $delivery->raw('response_status') > 0 ? (string) (int) $delivery->raw('response_status') : '—'));
        $this->line('Время:    ' . (int) $delivery->raw('duration_ms') . ' мс');

        if ((string) $delivery->raw('status') !== WebhookDelivery::SENT) {
            $this->fail('Состояние: ' . $delivery->label() . ' — ' . (string) $delivery->raw('error'));

            return 1;
        }

        $this->ok('Доставлена');

        return 0;
    }
}
