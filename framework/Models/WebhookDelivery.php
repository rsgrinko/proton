<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Models;

use Rsgrinko\Proton\Database\Connection;
use Rsgrinko\Proton\Database\Model\Model;
use Rsgrinko\Proton\Database\Model\Relations\BelongsTo;

/**
 * Посылка подписчику: что отправили, чем ответили и сколько раз пробовали.
 *
 * Журнал нужен ровно для одного разговора — «мы вам отправляли, вот код ответа
 * и время». Поэтому тело посылки хранится целиком, а ответ подписчика —
 * обрезанным: там бывает страница ошибки на сотни килобайт.
 */
final class WebhookDelivery extends Model
{
    protected static string $table = 'webhook_deliveries';

    protected bool $timestamps = false;

    protected array $casts = [
        'webhook_id'      => 'int',
        'attempts'        => 'int',
        'response_status' => 'int',
        'duration_ms'     => 'int',
    ];

    public const PENDING = 'pending';
    public const SENT    = 'sent';
    public const FAILED  = 'failed';

    /** @var array<string, string> Подписи состояний для панели */
    public const LABELS = [
        self::PENDING => 'в очереди',
        self::SENT    => 'доставлена',
        self::FAILED  => 'не доставлена',
    ];

    /** Сколько ответа подписчика оставляем в журнале */
    private const BODY_LIMIT = 2000;

    public function webhook(): BelongsTo
    {
        return $this->belongsTo(Webhook::class, 'webhook_id');
    }

    /**
     * Заводит посылку в состоянии «в очереди»: отправлять её будет воркер.
     */
    public static function start(Webhook $webhook, string $event, string $body): self
    {
        $delivery = new self();

        $delivery->forceFill([
            'webhook_id' => $webhook->id(),
            'event'      => $event,
            'payload'    => $body,
            'status'     => self::PENDING,
            'attempts'   => 0,
            'created_at' => Connection::now(),
        ])->save();

        return $delivery;
    }

    /**
     * Ответ подписчика получен — удачный или нет.
     */
    public function finish(bool $ok, int $status, string $body, string $error, int $duration, int $attempt): void
    {
        $this->forceFill([
            'status'          => $ok ? self::SENT : self::FAILED,
            'attempts'        => $attempt,
            'response_status' => $status,
            'response_body'   => mb_substr($body, 0, self::BODY_LIMIT),
            'error'           => mb_substr($error, 0, 500),
            'duration_ms'     => $duration,
            'finished_at'     => Connection::now(),
        ])->save();
    }

    public function label(): string
    {
        return self::LABELS[(string) $this->raw('status')] ?? (string) $this->raw('status');
    }

    /**
     * Чистка по сроку — зовётся воркером вместе с остальной уборкой.
     */
    public static function purge(int $days): int
    {
        if ($days <= 0) {
            return 0;
        }

        return self::query()
            ->where('created_at', '<', date('Y-m-d H:i:s', time() - $days * 86400))
            ->delete();
    }

    /**
     * Сколько посылок в каждом состоянии — для страницы состояния.
     *
     * @return array<string, int>
     */
    public static function stats(): array
    {
        $db = Connection::instance();

        if (!$db->hasTable('webhook_deliveries')) {
            return [];
        }

        $result = [self::PENDING => 0, self::SENT => 0, self::FAILED => 0];

        foreach ($db->select('SELECT status, COUNT(*) AS total FROM webhook_deliveries GROUP BY status') as $row) {
            $result[(string) $row['status']] = (int) $row['total'];
        }

        return $result;
    }
}
