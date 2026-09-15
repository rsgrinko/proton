<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Models;

use Rsgrinko\Proton\Database\Connection;
use Rsgrinko\Proton\Database\Model\Model;

/**
 * Заблокированный адрес: кого не пускать и до какого времени.
 *
 * Лимит по частоте (`RateLimiter`) тормозит перебор, но не закрывает дверь:
 * подождал окно — и снова. Блокировка закрывает совсем, на срок или навсегда.
 * Проверяется одним запросом на каждый заход, поэтому таблица маленькая, а
 * протухшие записи убирает воркер.
 */
final class BlockedIp extends Model
{
    protected static string $table = 'blocked_ips';

    protected array $fillable = ['ip', 'reason'];

    /**
     * Заблокировать адрес. Повторный вызов продлевает и переписывает причину.
     */
    public static function block(string $ip, string $reason = '', int $minutes = 60, int $byUserId = 0): self
    {
        $ip = trim($ip);

        /** @var self|null $existing */
        $existing = self::query()->where('ip', $ip)->first();

        $block = $existing ?? new self();

        $block->forceFill([
            'ip'         => $ip,
            'reason'     => $reason,
            'until'      => $minutes > 0 ? date('Y-m-d H:i:s', time() + $minutes * 60) : null,
            'created_by' => $byUserId > 0 ? $byUserId : null,
        ]);

        $block->save();

        return $block;
    }

    /**
     * Снять блокировку.
     */
    public static function unblock(string $ip): bool
    {
        /** @var self|null $block */
        $block = self::query()->where('ip', trim($ip))->first();

        return $block !== null && $block->delete();
    }

    /**
     * Закрыт ли адрес прямо сейчас. Просроченная запись не считается —
     * её уберёт уборка, а держать человека за дверью лишнюю минуту незачем.
     */
    public static function blocked(string $ip): bool
    {
        $ip = trim($ip);

        if ($ip === '') {
            return false;
        }

        $db = Connection::instance();

        if (!$db->hasTable('blocked_ips')) {
            return false;
        }

        $row = $db->selectOne(
            'SELECT until FROM blocked_ips WHERE ip = :ip',
            ['ip' => $ip]
        );

        if ($row === null) {
            return false;
        }

        $until = trim((string) ($row['until'] ?? ''));

        return $until === '' || strtotime($until) > time();
    }

    /**
     * Убрать записи, у которых срок вышел. Зовётся уборкой воркера.
     */
    public static function purgeExpired(): int
    {
        return self::query()
            ->whereNotNull('until')
            ->where('until', '<', Connection::now())
            ->delete();
    }

    /**
     * До какого времени закрыт — пусто означает «навсегда».
     */
    public function until(): string
    {
        return trim((string) $this->raw('until'));
    }

    public function permanent(): bool
    {
        return $this->until() === '';
    }
}
