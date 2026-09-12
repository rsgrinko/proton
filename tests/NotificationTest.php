<?php

declare(strict_types=1);

/**
 * Уведомления: каналы, лента, счётчик непрочитанных, адресация по праву
 * и уборка по сроку.
 *
 * Письма в тестах никуда не уходят: канал mail кладёт письмо в очередь, и тест
 * смотрит очередь, а не чужой SMTP.
 */

use Rsgrinko\Proton\Access\Permission;
use Rsgrinko\Proton\Mail\SendMailJob;
use Rsgrinko\Proton\Models\Role;
use Rsgrinko\Proton\Models\User;
use Rsgrinko\Proton\Models\UserNotification;
use Rsgrinko\Proton\Models\Webhook;
use Rsgrinko\Proton\Notifications\Notification;
use Rsgrinko\Proton\Notifications\Notify;
use Rsgrinko\Proton\Notifications\WebhookDisabledNotification;
use Rsgrinko\Proton\Queue\Queue;
use Rsgrinko\Proton\Webhooks\DeliverWebhookJob;

/**
 * Уведомление для проверок: каналы задаются снаружи.
 */
final class TestNotification extends Notification
{
    /**
     * @param array<int, string> $channels
     */
    public function __construct(private array $channels = [Notify::DATABASE])
    {
    }

    public function type(): string
    {
        return 'test.happened';
    }

    public function title(): string
    {
        return 'Кое-что случилось';
    }

    public function body(): string
    {
        return 'Подробности события';
    }

    public function url(): string
    {
        return 'https://example.com/куда-идти';
    }

    /**
     * @return array<int, string>
     */
    public function channels(): array
    {
        return $this->channels;
    }
}

test('уведомления: ложатся в ленту как есть', function (): void {
    withOwnDatabase(static function (): void {
        $user = User::register('notify_feed', 'секрет123', ['email' => 'feed@example.com']);

        assertSame([Notify::DATABASE], Notify::send($user, new TestNotification()));

        $notification = assertNotNull(UserNotification::query()->where('user_id', $user->id())->first());

        assertSame('test.happened', (string) $notification->raw('type'));
        assertSame('Кое-что случилось', (string) $notification->title);
        assertSame('Подробности события', (string) $notification->raw('body'));
        assertSame('https://example.com/куда-идти', (string) $notification->raw('url'));
        assertFalse($notification->read(), 'новое уведомление непрочитано');
    });
});

test('уведомления: письмо уходит очередью, а не прямо из страницы', function (): void {
    withOwnDatabase(static function (): void {
        $user = User::register('notify_mail', 'секрет123', ['email' => 'mail@example.com']);

        $done = Notify::send($user, new TestNotification([Notify::DATABASE, Notify::MAIL]));

        assertSame([Notify::DATABASE, Notify::MAIL], $done);
        assertSame(1, Queue::stats()[Queue::QUEUED] ?? 0, 'письмо встало в очередь');

        $job = assertNotNull(Queue::claim());

        assertSame(SendMailJob::class, (string) $job['job_class']);

        $payload = (array) json_decode((string) $job['payload'], true);

        assertSame(['mail@example.com'], $payload['to']);
        assertSame('Кое-что случилось', $payload['subject']);
        assertContains('Подробности события', (string) $payload['html']);
    });
});

test('уведомления: без годной почты лента всё равно срабатывает', function (): void {
    withOwnDatabase(static function (): void {
        $user = User::register('notify_no_mail', 'секрет123');

        // Пользователь без адреса: письму уйти некуда
        $done = Notify::send($user, new TestNotification([Notify::DATABASE, Notify::MAIL]));

        assertSame([Notify::DATABASE], $done);
        assertSame(0, Queue::stats()[Queue::QUEUED] ?? 0, 'письмо не ставилось');
        assertSame(1, UserNotification::query()->where('user_id', $user->id())->count());
    });
});

test('уведомления: опечатка в канале не теряется молча', function (): void {
    withOwnDatabase(static function (): void {
        $user = User::register('notify_bad_channel', 'секрет123', ['email' => 'bad@example.com']);

        // Неизвестный канал роняет доставку, но не вызвавший её код:
        // Notify пишет ошибку в лог и возвращает пустой список
        assertSame([], Notify::send($user, new TestNotification(['телеграф'])));
        assertSame(0, UserNotification::query()->where('user_id', $user->id())->count());
    });
});

test('уведомления: счётчик непрочитанных и чтение', function (): void {
    withOwnDatabase(static function (): void {
        $user  = User::register('notify_unread', 'секрет123', ['email' => 'unread@example.com']);
        $other = User::register('notify_other', 'секрет123', ['email' => 'other@example.com']);

        Notify::send($user, new TestNotification());
        Notify::send($user, new TestNotification());
        Notify::send($other, new TestNotification());

        assertSame(2, UserNotification::unreadFor($user->id()));
        assertSame(1, UserNotification::unreadFor($other->id()), 'чужие уведомления не считаются');

        $first = assertNotNull(UserNotification::query()->where('user_id', $user->id())->orderBy('id')->first());

        $first->markRead();

        $readAt = (string) assertNotNull(UserNotification::find($first->id()))->raw('read_at');

        assertTrue($readAt !== '');
        assertSame(1, UserNotification::unreadFor($user->id()));

        // Повторное чтение время не переписывает
        $first->markRead();

        assertSame($readAt, (string) assertNotNull(UserNotification::find($first->id()))->raw('read_at'));

        assertSame(1, UserNotification::markAllRead($user->id()));
        assertSame(0, UserNotification::unreadFor($user->id()));
        assertSame(1, UserNotification::unreadFor($other->id()), 'чужое не прочиталось');
    });
});

test('уведомления: адресация по праву берёт только тех, кому это нужно', function (): void {
    withOwnDatabase(static function (): void {
        $admin = User::register('notify_admin', 'секрет123', [
            'email'   => 'admin@example.com',
            'role_id' => Role::admin()?->id() ?? 0,
        ]);

        $plain = User::register('notify_plain', 'секрет123', [
            'email'   => 'plain@example.com',
            'role_id' => Role::query()->where('is_system', 0)->first()?->id() ?? 0,
        ]);

        $sleeping = User::register('notify_sleeping', 'секрет123', [
            'email'   => 'sleeping@example.com',
            'role_id' => Role::admin()?->id() ?? 0,
        ]);

        $sleeping->forceFill(['active' => 0])->save();

        $logins = array_map(
            static fn (User $user): string => (string) $user->login,
            Notify::whoCan(Permission::WEBHOOKS_MANAGE)
        );

        assertTrue(in_array('notify_admin', $logins, true), 'администратор с правом получает');
        assertFalse(in_array('notify_plain', $logins, true), 'обычному пользователю это не нужно');
        assertFalse(in_array('notify_sleeping', $logins, true), 'отключённым не пишем');

        assertSame(1, Notify::toPermission(Permission::WEBHOOKS_MANAGE, new TestNotification()));
        assertSame(1, UserNotification::unreadFor($admin->id()));
        assertSame(0, UserNotification::unreadFor($plain->id()));
    });
});

test('уведомления: отключённая подписка уведомляет тех, кто за неё отвечает', function (): void {
    withOwnDatabase(static function (): void {
        withConfig(['webhooks.disable_after' => 1], static function (): void {
            $admin = User::register('notify_webhooks', 'секрет123', [
                'email'   => 'hooks@example.com',
                'role_id' => Role::admin()?->id() ?? 0,
            ]);

            $webhook  = Webhook::add('Молчащий', 'https://example.com/silent', [Webhook::ALL_EVENTS]);
            $delivery = Rsgrinko\Proton\Webhooks\Webhooks::test($webhook);

            (new DeliverWebhookJob())->failed(['delivery_id' => $delivery->id()], 'нет ответа');

            assertFalse((bool) assertNotNull(Webhook::find($webhook->id()))->active, 'подписка отключилась');

            $notification = assertNotNull(
                UserNotification::query()->where('user_id', $admin->id())->orderBy('id', 'desc')->first()
            );

            assertSame('webhook.disabled', (string) $notification->raw('type'));
            assertContains('Молчащий', (string) $notification->title);
            assertContains('нет ответа', (string) $notification->raw('body'));
        });
    });
});

test('уведомления: уборка не трогает непрочитанные', function (): void {
    withOwnDatabase(static function (): void {
        $user = User::register('notify_purge', 'секрет123', ['email' => 'purge@example.com']);

        $old = UserNotification::add($user->id(), 'test.old', 'Старое прочитанное');

        $old->markRead();
        $old->forceFill(['created_at' => date('Y-m-d H:i:s', time() - 200 * 86400)])->save();

        $unread = UserNotification::add($user->id(), 'test.old', 'Старое непрочитанное');

        $unread->forceFill(['created_at' => date('Y-m-d H:i:s', time() - 200 * 86400)])->save();

        $fresh = UserNotification::add($user->id(), 'test.fresh', 'Свежее');

        assertSame(1, UserNotification::purge(90));
        assertNull(UserNotification::find($old->id()));
        assertNotNull(UserNotification::find($unread->id()), 'непрочитанное остаётся');
        assertNotNull(UserNotification::find($fresh->id()));
    });
});
