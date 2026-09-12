<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Models;

use Rsgrinko\Proton\Database\Connection;
use Rsgrinko\Proton\Database\Model\Model;

/**
 * Опись сеансов: с каких устройств человек входил.
 *
 * Нужна ровно затем, чтобы он видел свои устройства и мог завершить лишние.
 * Без неё в списке значились бы только те, кто отмечал «запомнить меня», а вход
 * без галки нельзя было бы ни увидеть, ни завершить.
 *
 * Идентификатора сессии в базе нет, только своё имя сеанса (sid) — светить
 * настоящий идентификатор незачем.
 */
final class UserSession extends Model
{
    protected static string $table = 'user_sessions';

    protected bool $timestamps = false;

    /**
     * Открывает сеанс. $selector — долгая кука этого же устройства, если она есть.
     */
    public static function open(int $userId, string $sid, string $ip, string $agent, string $selector = ''): void
    {
        $session = new self();

        $session->forceFill([
            'user_id'            => $userId,
            'sid'                => hash('sha256', $sid),
            'remember_selector'  => $selector,
            'ip'                 => $ip,
            'user_agent'         => mb_substr($agent, 0, 255),
            'created_at'         => Connection::now(),
            'last_seen_at'       => Connection::now(),
        ])->save();
    }

    /**
     * Отмечает, что сеансом пользовались.
     */
    public static function touch(string $sid, string $ip): void
    {
        self::query()
            ->where('sid', hash('sha256', $sid))
            ->update(['last_seen_at' => Connection::now(), 'ip' => $ip]);
    }

    /**
     * Привязывает сеанс к долгой куке — после тихого входа по ней.
     */
    public static function attach(string $sid, string $selector): void
    {
        self::query()
            ->where('sid', hash('sha256', $sid))
            ->update(['remember_selector' => $selector]);
    }

    /**
     * Сеанс по своему имени. Метод назван не find(): у модели уже есть find()
     * по первичному ключу, и подменять его другой сигнатурой нельзя.
     */
    public static function bySid(string $sid): ?self
    {
        /** @var self|null $session */
        $session = self::query()->where('sid', hash('sha256', $sid))->first();

        return $session;
    }

    /**
     * Человек вышел сам — строку убираем совсем, показывать её незачем.
     */
    public static function close(string $sid): void
    {
        self::query()->where('sid', hash('sha256', $sid))->forceDelete();
    }

    public function revoked(): bool
    {
        return trim((string) $this->raw('revoked_at')) !== '';
    }

    /**
     * Завершает сеанс с другого устройства: гасим и сессию, и её долгую куку.
     */
    public function revoke(): void
    {
        $this->forceFill(['revoked_at' => Connection::now()])->save();

        $selector = (string) $this->raw('remember_selector');

        if ($selector !== '') {
            RememberToken::query()->where('selector', $selector)->forceDelete();
        }
    }

    /**
     * Убирает старые записи — зовётся воркером.
     */
    public static function purge(int $days = 60): int
    {
        return self::query()
            ->where('last_seen_at', '<', date('Y-m-d H:i:s', time() - max(1, $days) * 86400))
            ->forceDelete();
    }
}
