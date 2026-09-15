<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Notifications;

use Rsgrinko\Proton\Http\Router;
use Rsgrinko\Proton\Models\ApiToken;

/**
 * Ключ API скоро перестанет работать.
 *
 * Уходит владельцу ключа, а не администратору: чинить интеграцию ему.
 * Молчание тут стоит дорого — ключ истекает ночью, а утром «внезапно ничего
 * не работает».
 */
final class ApiKeyExpiringNotification extends Notification
{
    public function __construct(private ApiToken $token, private int $daysLeft)
    {
    }

    public function type(): string
    {
        return 'key.expiring';
    }

    public function title(): string
    {
        return 'Ключ API «' . (string) $this->token->name . '» скоро истечёт';
    }

    public function body(): string
    {
        $days = $this->daysLeft <= 0 ? 'сегодня' : 'через ' . $this->daysLeft . ' дн.';

        return 'Ключ ' . $this->token->mask() . ' перестанет работать ' . $days
            . ' (' . $this->token->expiresAt() . '). Выпустите новый и замените его в интеграции:'
            . ' продлить существующий нельзя — в базе лежит только хеш.';
    }

    public function url(): string
    {
        return Router::absolute('admin.tokens');
    }

    /**
     * @return array<int, string>
     */
    public function channels(): array
    {
        return [Notify::DATABASE, Notify::MAIL];
    }
}
