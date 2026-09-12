<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Console\Commands;

use Rsgrinko\Proton\Console\Command;
use Rsgrinko\Proton\Models\Webhook;
use Rsgrinko\Proton\Models\WebhookDelivery;
use Rsgrinko\Proton\Webhooks\Webhooks;

/**
 * список вебхуков.
 */
final class WebhookListCommand extends Command
{
    public function name(): string
    {
        return 'webhook:list';
    }

    public function description(): string
    {
        return 'список вебхуков и состояние посылок';
    }

    public function usage(): string
    {
        return 'webhook:list [--events]';
    }

    public function run(): int
    {
        if ($this->hasOption('events')) {
            return $this->printEvents();
        }

        /** @var array<int, Webhook> $webhooks */
        $webhooks = Webhook::query()->orderBy('id')->get();

        if ($webhooks === []) {
            $this->line('Вебхуков нет. Завести: раздел «Вебхуки» в панели');

            return 0;
        }

        $rows = [];

        foreach ($webhooks as $webhook) {
            $events = $webhook->events();

            $rows[] = [
                (string) $webhook->id(),
                (string) $webhook->name,
                (string) $webhook->url,
                in_array(Webhook::ALL_EVENTS, $events, true) ? 'все' : (string) count($events),
                (bool) $webhook->active ? 'да' : 'нет',
                (int) $webhook->raw('last_status') > 0 ? (string) (int) $webhook->raw('last_status') : '—',
                (string) ($webhook->raw('last_sent_at') ?? '—'),
            ];
        }

        $this->table(['id', 'Название', 'Адрес', 'События', 'Включена', 'Ответ', 'Последняя посылка'], $rows);

        $stats = WebhookDelivery::stats();

        $this->line();
        $this->line(
            'Посылки: доставлено ' . (int) ($stats[WebhookDelivery::SENT] ?? 0)
            . ', в очереди ' . (int) ($stats[WebhookDelivery::PENDING] ?? 0)
            . ', не доставлено ' . (int) ($stats[WebhookDelivery::FAILED] ?? 0)
        );

        return 0;
    }

    /**
     * Реестр событий: на что вообще можно подписаться.
     */
    private function printEvents(): int
    {
        $rows = [];

        foreach (Webhooks::groups() as $group => $events) {
            foreach ($events as $code => $label) {
                $rows[] = [$group, $code, $label];
            }
        }

        $this->table(['Раздел', 'Событие', 'Что случилось'], $rows);

        return 0;
    }
}
