<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Models;

use Rsgrinko\Proton\Database\Connection;
use Rsgrinko\Proton\Database\Model\Model;
use Rsgrinko\Proton\Database\Model\Relations\BelongsTo;

/**
 * Запись в ленте уведомлений: что человеку показать и прочитал ли он это.
 *
 * Модель называется UserNotification, а не Notification, чтобы не путаться с
 * самим уведомлением (`Notifications\Notification` — описание «что случилось»,
 * эта запись — его след в ленте одного пользователя).
 */
final class UserNotification extends Model
{
    protected static string $table = 'notifications';

    protected bool $timestamps = false;

    protected array $casts = ['user_id' => 'int'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Кладёт уведомление в ленту.
     */
    public static function add(int $userId, string $type, string $title, string $body = '', string $url = ''): self
    {
        $notification = new self();

        $notification->forceFill([
            'user_id'    => $userId,
            'type'       => mb_substr($type, 0, 64),
            'title'      => mb_substr($title, 0, 191),
            'body'       => $body,
            'url'        => mb_substr($url, 0, 500),
            'created_at' => Connection::now(),
        ])->save();

        return $notification;
    }

    /**
     * Сколько непрочитанных — число у ссылки в шапке.
     */
    public static function unreadFor(int $userId): int
    {
        if ($userId <= 0 || !Connection::instance()->hasTable('notifications')) {
            return 0;
        }

        return self::query()->where('user_id', $userId)->whereNull('read_at')->count();
    }

    public function read(): bool
    {
        return (string) $this->raw('read_at') !== '';
    }

    /**
     * Отмечает прочитанным. Повторно время не переписываем: в ленте видно,
     * когда человек это увидел.
     */
    public function markRead(): void
    {
        if ($this->read()) {
            return;
        }

        $this->forceFill(['read_at' => Connection::now()])->save();
    }

    /**
     * Отмечает прочитанным всё у пользователя. Возвращает число строк.
     */
    public static function markAllRead(int $userId): int
    {
        return self::query()
            ->where('user_id', $userId)
            ->whereNull('read_at')
            ->update(['read_at' => Connection::now()]);
    }

    /**
     * Чистка по сроку — зовётся воркером. Непрочитанные не трогаем: человек
     * их ещё не видел, и молча убирать их нечестно.
     */
    public static function purge(int $days): int
    {
        if ($days <= 0) {
            return 0;
        }

        return self::query()
            ->whereNotNull('read_at')
            ->where('created_at', '<', date('Y-m-d H:i:s', time() - $days * 86400))
            ->delete();
    }
}
