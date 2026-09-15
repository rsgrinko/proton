<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Models;

use Rsgrinko\Proton\Database\Model\Model;
use Rsgrinko\Proton\Support\ClientIp;
use Throwable;

/**
 * Посылка, пришедшая от чужой системы.
 *
 * Хранится целиком: без тела разбирать «мы отправили, а вы не приняли» нечем.
 * Старые записи убирает воркер вместе с журналом исходящих доставок.
 */
final class IncomingHook extends Model
{
    protected static string $table = 'incoming_hooks';

    protected array $casts = ['payload' => 'json'];

    /** Принята и обработана */
    public const DONE = 'done';

    /** Подпись не сошлась или источник не тот */
    public const REJECTED = 'rejected';

    /** Обработчик упал */
    public const FAILED = 'failed';

    /** Источник неизвестен */
    public const UNKNOWN = 'unknown';

    /** @var array<string, string> Подписи для панели */
    public const LABELS = [
        self::DONE     => 'принята',
        self::REJECTED => 'отклонена',
        self::FAILED   => 'ошибка обработки',
        self::UNKNOWN  => 'чужой источник',
    ];

    /**
     * Записать посылку. Журнал не должен мешать приёму, поэтому падение здесь
     * гасится: хуже потерять запись в журнале, чем саму посылку.
     *
     * @param array<string, mixed> $payload
     */
    public static function write(string $source, string $event, array $payload, string $status, string $error = ''): void
    {
        try {
            $hook = new self();

            $hook->forceFill([
                'source'  => mb_substr($source, 0, 64),
                'event'   => mb_substr($event, 0, 191),
                'ip'      => ClientIp::fromGlobals(),
                'status'  => $status,
                'payload' => $payload,
                'error'   => $error !== '' ? mb_substr($error, 0, 1000) : null,
            ]);

            $hook->save();
        } catch (Throwable) {
            // Молча: приём посылки важнее записи о нём
        }
    }

    public static function label(string $status): string
    {
        return self::LABELS[$status] ?? $status;
    }

    /**
     * Уборка старых записей.
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
}
