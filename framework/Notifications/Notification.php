<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Notifications;

use Rsgrinko\Proton\Mail\Message;
use Rsgrinko\Proton\Models\User;

/**
 * Уведомление: что случилось и как об этом сказать человеку.
 *
 *     final class OrderPaid extends Notification
 *     {
 *         public function __construct(private Order $order)
 *         {
 *         }
 *
 *         public function type(): string  { return 'order.paid'; }
 *         public function title(): string { return 'Заказ №' . $this->order->id() . ' оплачен'; }
 *         public function body(): string  { return 'Сумма: ' . $this->order->total . ' ₽'; }
 *         public function url(): string   { return Router::url('orders.show', ['id' => $this->order->id()]); }
 *     }
 *
 *     Notify::send($user, new OrderPaid($order));
 *
 * Каналы перечисляет само уведомление: что-то стоит и в ленте, и письмом,
 * а что-то — только в ленте. Письмо собирается из заголовка и текста общим
 * шаблоном; нужно своё — переопределите mail().
 */
abstract class Notification
{
    /**
     * Короткий код события: `webhook.disabled`, `order.paid`. По нему
     * уведомления отличают друг от друга, не разбирая текст.
     */
    public function type(): string
    {
        return 'notification';
    }

    /**
     * Заголовок — он же тема письма.
     */
    abstract public function title(): string;

    /**
     * Текст. Может быть пустым: иногда заголовка достаточно.
     */
    public function body(): string
    {
        return '';
    }

    /**
     * Куда вести по нажатию. Пусто — уведомление без перехода.
     */
    public function url(): string
    {
        return '';
    }

    /**
     * Каналы доставки.
     *
     * @return array<int, string>
     */
    public function channels(): array
    {
        return [Notify::DATABASE];
    }

    /**
     * Письмо. По умолчанию — общий шаблон с заголовком, текстом и кнопкой.
     */
    public function mail(User $user): Message
    {
        return Message::to((string) $user->email)
            ->subject($this->title())
            ->view('mail/notification', [
                'user'  => $user,
                'title' => $this->title(),
                'body'  => $this->body(),
                'url'   => $this->url(),
            ]);
    }
}
