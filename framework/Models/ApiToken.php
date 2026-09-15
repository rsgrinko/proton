<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Models;

use Rsgrinko\Proton\Database\Connection;
use Rsgrinko\Proton\Database\Model\Model;
use Rsgrinko\Proton\Database\Model\Relations\BelongsTo;
use Rsgrinko\Proton\Support\Str;

/**
 * Ключ доступа к API. Выглядит так: ptn_<префикс>_<секрет>.
 *
 * Префикс хранится открыто — по нему быстро находится строка, секрет только
 * хешем: утёкшая база не даёт войти. Целиком ключ виден один раз, сразу после
 * выпуска, и повторить это нельзя.
 */
final class ApiToken extends Model
{
    protected static string $table = 'api_tokens';

    protected array $fillable = ['name', 'user_id', 'active'];

    protected array $hidden = ['token_hash'];

    protected array $casts = ['active' => 'bool', 'user_id' => 'int'];

    /** Начало ключа — по нему его узнают в логах и в поддержке */
    public const PREFIX = 'ptn';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Выпускает ключ. Возвращает пару: сама запись и значение ключа —
     * второй раз его показать будет негде.
     *
     * @return array{token: self, key: string}
     */
    public static function issue(string $name, int $userId, string $ipList = '', int $days = 0): array
    {
        $prefix = Str::random(8);
        $secret = Str::random(40);
        $key    = self::PREFIX . '_' . $prefix . '_' . $secret;

        $token = new self();

        $token->forceFill([
            'name'        => $name !== '' ? $name : 'Ключ ' . date('d.m.Y'),
            'user_id'     => $userId,
            'prefix'      => $prefix,
            'token_hash'  => self::hash($key),
            'allowed_ips' => $ipList,
            'active'      => 1,
            // Ключ без срока живёт вечно — это осознанный выбор того, кто его выпускал
            'expires_at'  => $days > 0 ? date('Y-m-d H:i:s', time() + $days * 86400) : null,
            'created_at'  => Connection::now(),
        ])->save();

        return ['token' => $token, 'key' => $key];
    }

    /**
     * Находит действующий ключ по присланному значению.
     */
    public static function match(string $key): ?self
    {
        $parts = explode('_', $key);

        if (count($parts) < 3 || $parts[0] !== self::PREFIX) {
            return null;
        }

        /** @var self|null $token */
        $token = self::query()->where('prefix', $parts[1])->where('active', 1)->first();

        if ($token === null) {
            return null;
        }

        if (!hash_equals((string) $token->raw('token_hash'), self::hash($key))) {
            return null;
        }

        $expires = trim((string) $token->raw('expires_at'));

        if ($expires !== '' && strtotime($expires) < time()) {
            return null;
        }

        return $token;
    }

    /**
     * До какого времени действует; пусто — без срока.
     */
    public function expiresAt(): string
    {
        return trim((string) $this->raw('expires_at'));
    }

    /**
     * Сколько дней осталось; -1 — ключ без срока.
     */
    public function daysLeft(): int
    {
        $expires = $this->expiresAt();

        if ($expires === '') {
            return -1;
        }

        $time = strtotime($expires);

        return $time === false ? -1 : (int) ceil(($time - time()) / 86400);
    }

    public function expired(): bool
    {
        $expires = $this->expiresAt();

        return $expires !== '' && strtotime($expires) < time();
    }

    /**
     * Действующие ключи, которым осталось не больше стольких дней. По ним
     * рассылаются напоминания владельцам.
     *
     * @return array<int, self>
     */
    public static function expiringWithin(int $days): array
    {
        /** @var array<int, self> $tokens */
        $tokens = self::query()
            ->where('active', 1)
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', Connection::now())
            ->where('expires_at', '<=', date('Y-m-d H:i:s', time() + max(1, $days) * 86400))
            ->orderBy('expires_at')
            ->get();

        return $tokens;
    }

    /**
     * Отмечает использование: по этой дате видно забытые ключи.
     */
    public function markUsed(string $ip): void
    {
        $this->forceFill(['last_used_at' => Connection::now(), 'last_used_ip' => $ip])->save();
    }

    /**
     * Как показывать ключ, не раскрывая его.
     */
    public function mask(): string
    {
        return self::PREFIX . '_' . (string) $this->raw('prefix') . '_' . str_repeat('•', 8);
    }

    public static function hash(string $key): string
    {
        return hash('sha256', $key);
    }
}
