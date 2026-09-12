<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Notifications;

use Rsgrinko\Proton\Database\Connection;
use Rsgrinko\Proton\Events\Events;
use Rsgrinko\Proton\Models\Role;
use Rsgrinko\Proton\Models\User;
use Rsgrinko\Proton\Models\UserNotification;
use Rsgrinko\Proton\Support\Config;
use Rsgrinko\Proton\Support\Logger;
use Rsgrinko\Proton\Support\ProtonException;
use Rsgrinko\Proton\Support\Validator;
use Throwable;

/**
 * Доставка уведомлений по каналам.
 *
 *     Notify::send($user, new OrderPaid($order));
 *     Notify::toPermission(Permission::SYSTEM_MANAGE, new WebhookDisabledNotification($webhook));
 *
 * Два канала: `database` — запись в ленте пользователя, `mail` — письмо через
 * очередь (страница не ждёт чужой SMTP). Какие нужны, решает само уведомление.
 *
 * Уведомление никогда не роняет то, что его вызвало: это сообщение о событии,
 * а не часть действия. Сорвалось — строка в логе.
 */
final class Notify
{
    public const DATABASE = 'database';
    public const MAIL     = 'mail';

    /**
     * Отправляет уведомление одному человеку. Возвращает каналы, которые
     * действительно отработали.
     *
     * @return array<int, string>
     */
    public static function send(User $user, Notification $notification): array
    {
        $done = [];

        foreach ($notification->channels() as $channel) {
            try {
                if (self::deliver($channel, $user, $notification)) {
                    $done[] = $channel;
                }
            } catch (Throwable $e) {
                (new Logger('notifications'))->error('Уведомление не доставлено', [
                    'channel' => $channel,
                    'type'    => $notification->type(),
                    'user'    => $user->id(),
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        if ($done !== []) {
            Events::fire('notification.sent', [
                'user'         => $user,
                'notification' => $notification,
                'channels'     => $done,
            ]);
        }

        return $done;
    }

    /**
     * Отправляет нескольким.
     *
     * @param array<int, User> $users
     */
    public static function sendMany(array $users, Notification $notification): int
    {
        $count = 0;

        foreach ($users as $user) {
            if (self::send($user, $notification) !== []) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Отправляет всем, у кого есть право: так уведомляют «тех, кто отвечает
     * за сервис», не заводя отдельного списка адресатов.
     */
    public static function toPermission(string $permission, Notification $notification): int
    {
        return self::sendMany(self::whoCan($permission), $notification);
    }

    /**
     * Действующие пользователи с нужным правом.
     *
     * @return array<int, User>
     */
    public static function whoCan(string $permission): array
    {
        if (!Connection::instance()->hasTable('users')) {
            return [];
        }

        $roles = [];

        foreach (Role::all() as $role) {
            if (in_array($permission, $role->permissions(), true)) {
                $roles[] = $role->id();
            }
        }

        if ($roles === []) {
            return [];
        }

        /** @var array<int, User> $users */
        $users = User::query()->where('active', 1)->whereIn('role_id', $roles)->orderBy('id')->get();

        return $users;
    }

    /**
     * Уборка прочитанных уведомлений по сроку — зовётся воркером.
     */
    public static function purge(): int
    {
        return UserNotification::purge((int) Config::get('notifications.keep_days', 90));
    }

    /**
     * Один канал. true — доставлено.
     */
    private static function deliver(string $channel, User $user, Notification $notification): bool
    {
        return match ($channel) {
            self::DATABASE => self::toDatabase($user, $notification),
            self::MAIL     => self::toMail($user, $notification),
            // Чужой канал — не молчим: опечатка в channels() иначе теряется
            default        => throw new ProtonException('Неизвестный канал уведомлений: ' . $channel),
        };
    }

    private static function toDatabase(User $user, Notification $notification): bool
    {
        if (!Connection::instance()->hasTable('notifications')) {
            return false;
        }

        UserNotification::add(
            $user->id(),
            $notification->type(),
            $notification->title(),
            $notification->body(),
            $notification->url()
        );

        return true;
    }

    private static function toMail(User $user, Notification $notification): bool
    {
        $email = trim((string) $user->email);

        // Адреса нет или он негодный — письмо не уйдёт, но лента уже сработала
        if (!Validator::isEmail($email)) {
            return false;
        }

        $notification->mail($user)->queue();

        return true;
    }
}
