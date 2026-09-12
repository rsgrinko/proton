<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Notifications;

use Rsgrinko\Proton\Http\Router;
use Rsgrinko\Proton\Models\Webhook;

/**
 * Подписка на события отключилась сама: подписчик не отвечал слишком долго.
 *
 * Уходит тем, кто отвечает за вебхуки (право webhooks.manage), и письмом тоже:
 * молча отключённая подписка — это тихо потерянные события, о таком нужно
 * узнавать сразу, а не из журнала через неделю.
 */
final class WebhookDisabledNotification extends Notification
{
    public function __construct(private Webhook $webhook, private string $error = '')
    {
    }

    public function type(): string
    {
        return 'webhook.disabled';
    }

    public function title(): string
    {
        return 'Подписка «' . (string) $this->webhook->name . '» отключена';
    }

    public function body(): string
    {
        $text = 'Подписчик ' . (string) $this->webhook->url . ' не отвечает, неудач подряд — '
            . (int) $this->webhook->raw('failures') . '. Подписка отключена, события ему '
            . 'больше не уходят.';

        if ($this->error !== '') {
            $text .= ' Последняя ошибка: ' . $this->error . '.';
        }

        return $text . ' Починив подписчика, включите подписку обратно в разделе «Вебхуки».';
    }

    public function url(): string
    {
        // Адрес с доменом: уведомление уходит и письмом, а там путь не кликается
        return Router::absolute('admin.webhooks.show', ['id' => $this->webhook->id()]);
    }

    /**
     * @return array<int, string>
     */
    public function channels(): array
    {
        return [Notify::DATABASE, Notify::MAIL];
    }
}
