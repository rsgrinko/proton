<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Notifications;

use Rsgrinko\Proton\Http\Router;

/**
 * Показатель вышел за порог: очередь встала, посыпались ошибки, кончается диск.
 *
 * Уходит тем, кто отвечает за обслуживание (право system.manage), и письмом:
 * такое замечают не глядя в панель, а когда уже поздно.
 */
final class HealthAlertNotification extends Notification
{
    public function __construct(private string $problem, private string $message)
    {
    }

    public function type(): string
    {
        return 'health.' . $this->problem;
    }

    public function title(): string
    {
        return 'Приложение просит внимания';
    }

    public function body(): string
    {
        return $this->message . '. Подробности — на странице «Состояние»; пороги задаются'
            . ' настройками MONITOR_* в .env.';
    }

    public function url(): string
    {
        return Router::absolute('admin.system');
    }

    /**
     * @return array<int, string>
     */
    public function channels(): array
    {
        return [Notify::DATABASE, Notify::MAIL];
    }
}
