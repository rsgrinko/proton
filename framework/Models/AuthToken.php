<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Models;

use Rsgrinko\Proton\Database\Connection;
use Rsgrinko\Proton\Database\Model\Model;
use Rsgrinko\Proton\Support\Config;
use Rsgrinko\Proton\Support\Str;

/**
 * Одноразовая ссылка: подтверждение почты и сброс пароля.
 *
 * В базе только хеш токена, а срок жизни короткий (auth.link_lifetime).
 * Использованный токен помечается, а не удаляется: по нему видно, что человек
 * действительно перешёл по ссылке.
 */
final class AuthToken extends Model
{
    protected static string $table = 'auth_tokens';

    protected bool $timestamps = false;

    public const VERIFY = 'verify';
    public const RESET  = 'reset';

    /**
     * Выдаёт токен и возвращает его открытое значение — оно уходит в письмо
     * и больше нигде не появляется.
     */
    public static function issue(int $userId, string $type, string $ip = ''): string
    {
        // Прежние ссылки того же типа гасим: действующей должна быть одна
        self::query()->where('user_id', $userId)->where('type', $type)->whereNull('used_at')
            ->update(['used_at' => Connection::now()]);

        $token = Str::random(48);

        $model = new self();

        $model->forceFill([
            'user_id'    => $userId,
            'type'       => $type,
            'token_hash' => self::hash($token),
            'expires_at' => Connection::at(max(300, (int) Config::get('auth.link_lifetime', 86400))),
            'ip'         => $ip,
            'created_at' => Connection::now(),
        ])->save();

        return $token;
    }

    /**
     * Находит действующий токен по значению из ссылки.
     */
    public static function match(string $token, string $type): ?self
    {
        if (trim($token) === '') {
            return null;
        }

        /** @var self|null $found */
        $found = self::query()
            ->where('token_hash', self::hash($token))
            ->where('type', $type)
            ->whereNull('used_at')
            ->first();

        if ($found === null) {
            return null;
        }

        if (strtotime((string) $found->raw('expires_at')) < time()) {
            return null;
        }

        return $found;
    }

    /**
     * Помечает токен использованным — второй переход по той же ссылке
     * не должен ничего менять.
     */
    public function markUsed(): void
    {
        $this->forceFill(['used_at' => Connection::now()])->save();
    }

    public function user(): ?User
    {
        return User::find((int) $this->raw('user_id'));
    }

    /**
     * Убирает просроченные и использованные — зовётся воркером.
     */
    public static function purge(): int
    {
        return self::query()->where('expires_at', '<', date('Y-m-d H:i:s', time() - 86400))->forceDelete();
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
