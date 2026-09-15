<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Models;

use Rsgrinko\Proton\Auth\Crypto;
use Rsgrinko\Proton\Database\Connection;
use Rsgrinko\Proton\Database\Model\Model;
use Rsgrinko\Proton\Database\Model\Relations\HasMany;
use Rsgrinko\Proton\Support\Str;

/**
 * Подписка на события: куда и о чём стучаться.
 *
 * Секрет подписки хранится шифрованным (Crypto, мастер-ключ APP_KEY), а не
 * хешем: им подписывается каждая посылка, значит читать его придётся снова.
 * Показывается он только владельцу подписки в панели.
 *
 * Список событий — JSON-массив кодов; звёздочка означает «все события реестра».
 */
final class Webhook extends Model
{
    protected static string $table = 'webhooks';

    protected array $fillable = ['name', 'url', 'active'];

    protected array $hidden = ['secret'];

    protected array $casts = ['active' => 'bool', 'events' => 'json', 'failures' => 'int', 'last_status' => 'int'];

    /** Мягкое удаление: подписку можно вернуть, журнал доставок остаётся */
    protected bool $softDelete = true;

    /** Все события сразу: новое событие реестра подписка получит без правки */
    public const ALL_EVENTS = '*';

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class, 'webhook_id');
    }

    /**
     * Заводит подписку. Секрет либо задан руками, либо придумывается сам.
     *
     * @param array<int, string> $events
     */
    public static function add(string $name, string $url, array $events, string $secret = ''): self
    {
        $webhook = new self();

        $webhook->forceFill([
            'name'       => $name !== '' ? $name : 'Подписка ' . date('d.m.Y'),
            'url'        => $url,
            'events'     => json_encode(array_values($events), JSON_UNESCAPED_UNICODE),
            'secret'     => Crypto::encrypt($secret !== '' ? $secret : self::newSecret()),
            'active'     => 1,
            'created_at' => Connection::now(),
            'updated_at' => Connection::now(),
        ])->save();

        return $webhook;
    }

    /**
     * Новый случайный секрет — его же подписчик прописывает у себя.
     */
    public static function newSecret(): string
    {
        return 'whs_' . Str::random(40);
    }

    /**
     * Секрет в открытом виде.
     */
    public function secret(): string
    {
        return Crypto::decrypt((string) $this->raw('secret'));
    }

    /**
     * Заменить секрет: старый перестаёт подходить сразу.
     */
    public function rotateSecret(): string
    {
        $secret = self::newSecret();

        $this->forceFill(['secret' => Crypto::encrypt($secret), 'updated_at' => Connection::now()])->save();

        return $secret;
    }

    /**
     * События подписки списком.
     *
     * @return array<int, string>
     */
    public function events(): array
    {
        // Читаем raw, а не через приведение: у новой подписки колонки ещё нет
        $events = json_decode((string) $this->raw('events'), true);

        return is_array($events) ? array_map(static fn (mixed $event): string => (string) $event, $events) : [];
    }

    /**
     * Ждёт ли подписка такое событие.
     */
    public function wants(string $event): bool
    {
        $events = $this->events();

        return in_array(self::ALL_EVENTS, $events, true) || in_array($event, $events, true);
    }

    /**
     * Подписки, которым нужно это событие. Фильтр по событию делаем в PHP:
     * список хранится JSON-строкой, и в SQL его не развернуть одинаково
     * в обеих СУБД.
     *
     * @return array<int, self>
     */
    public static function listeningTo(string $event): array
    {
        /** @var array<int, self> $active */
        $active = self::query()->where('active', 1)->orderBy('id')->get();

        return array_values(array_filter($active, static fn (self $webhook): bool => $webhook->wants($event)));
    }

    /**
     * Отметка об удачной посылке: счётчик подряд неудачных обнуляется.
     */
    public function markDelivered(int $status): void
    {
        $this->forceFill([
            'last_status'  => $status,
            'last_error'   => '',
            'last_sent_at' => Connection::now(),
            'failures'     => 0,
            'updated_at'   => Connection::now(),
        ])->save();
    }

    /**
     * Отметка о неудачной попытке: в панели видно, что подписчик отвечает
     * не так, ещё до того как кончились повторы. Счётчик неудач тут не растим —
     * он про посылки, а не про попытки.
     */
    public function markAttempt(int $status, string $error): void
    {
        $this->forceFill([
            'last_status'  => $status,
            'last_error'   => mb_substr($error, 0, 500),
            'last_sent_at' => Connection::now(),
            'updated_at'   => Connection::now(),
        ])->save();
    }

    /**
     * Отметка о неудаче. Подписка, которая не отвечает слишком долго,
     * отключается сама: иначе очередь вечно возит посылки в никуда.
     */
    public function markFailed(int $status, string $error, int $disableAfter = 0): bool
    {
        $failures = (int) $this->raw('failures') + 1;
        $disabled = $disableAfter > 0 && $failures >= $disableAfter;

        $this->forceFill([
            'last_status'  => $status,
            'last_error'   => mb_substr($error, 0, 500),
            'last_sent_at' => Connection::now(),
            'failures'     => $failures,
            'active'       => $disabled ? 0 : (int) $this->raw('active'),
            'updated_at'   => Connection::now(),
        ])->save();

        return $disabled;
    }

    /**
     * Подпись посылки: HMAC-SHA256 от «время.тело» секретом подписки.
     *
     * Время внутри подписи нужно, чтобы перехваченную посылку нельзя было
     * повторить через сутки — подписчик сверяет его со своим.
     */
    public function sign(string $body, int $timestamp): string
    {
        return 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $this->secret());
    }

    /**
     * Как показывать секрет, не раскрывая его целиком.
     */
    public function maskedSecret(): string
    {
        $secret = $this->secret();

        return $secret === '' ? '—' : mb_substr($secret, 0, 8) . str_repeat('•', 8);
    }
}
