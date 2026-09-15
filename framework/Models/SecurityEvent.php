<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Models;

use Rsgrinko\Proton\Database\Connection;
use Rsgrinko\Proton\Database\Model\Model;
use Rsgrinko\Proton\Support\ClientIp;
use Throwable;

/**
 * Подозрительное событие: неудачный вход, отказ по правам, негодный ключ.
 *
 * Отдельно от журнала действий (`audit_log`) нарочно: там записи о том, что
 * человек сделал, здесь — о том, что у него не вышло. Смешивать неудобно —
 * в аудите начинается шум от перебора паролей.
 *
 * Запись никогда не роняет то, что её вызвало: у неудачного входа своя судьба.
 */
final class SecurityEvent extends Model
{
    protected static string $table = 'security_events';

    /** Не сошёлся пароль или логин */
    public const LOGIN_FAILED = 'login.failed';

    /** Лимит попыток исчерпан */
    public const LOGIN_BLOCKED = 'login.blocked';

    /** Зашли с закрытого адреса */
    public const IP_BLOCKED = 'ip.blocked';

    /** Не хватило прав */
    public const ACCESS_DENIED = 'access.denied';

    /** Ключ API не подошёл */
    public const KEY_INVALID = 'key.invalid';

    /** @var array<string, string> Подписи для панели */
    public const LABELS = [
        self::LOGIN_FAILED  => 'неудачный вход',
        self::LOGIN_BLOCKED => 'перебор паролей',
        self::IP_BLOCKED    => 'заход с закрытого адреса',
        self::ACCESS_DENIED => 'нет прав',
        self::KEY_INVALID   => 'негодный ключ',
    ];

    /**
     * Записать событие.
     */
    public static function record(string $kind, string $login = '', string $path = '', string $agent = ''): void
    {
        try {
            $event = new self();

            $event->forceFill([
                'kind'  => $kind,
                'ip'    => ClientIp::fromGlobals(),
                'login' => mb_substr($login, 0, 191),
                'path'  => mb_substr($path, 0, 191),
                'agent' => mb_substr($agent, 0, 255),
            ]);

            $event->save();
        } catch (Throwable) {
            // Журнал безопасности не должен мешать работе: не записалось — и ладно
        }
    }

    public static function label(string $kind): string
    {
        return self::LABELS[$kind] ?? $kind;
    }

    /**
     * Сколько событий такого рода с этого адреса за последние минуты —
     * по этому числу решают, не пора ли закрыть адрес.
     */
    public static function countFor(string $ip, string $kind, int $minutes): int
    {
        return self::query()
            ->where('ip', $ip)
            ->where('kind', $kind)
            ->where('created_at', '>=', date('Y-m-d H:i:s', time() - max(1, $minutes) * 60))
            ->count();
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
