<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\RateLimit;

use Rsgrinko\Proton\Database\Connection;

/**
 * Счётчики с окном: попытки входа, лимиты API, «не чаще N раз в минуту».
 *
 * Лежат в таблице counters — ключ, значение и время сброса. Просроченная строка
 * считается нулём и следующим попаданием открывает новое окно: без этого
 * блокировка держалась бы дольше обещанного, до самой чистки воркером.
 */
final class RateLimiter
{
    private Connection $db;

    public function __construct(?Connection $db = null)
    {
        $this->db = $db ?? Connection::instance();
    }

    /**
     * Сколько раз ключ сработал в текущем окне.
     */
    public function count(string $key): int
    {
        $row = $this->db->selectOne(
            'SELECT value, expires_at FROM counters WHERE counter_key = :key',
            ['key' => $key]
        );

        if ($row === null) {
            return 0;
        }

        $expires = (string) ($row['expires_at'] ?? '');

        // Окно кончилось — строка есть, но считается нулём
        if ($expires !== '' && strtotime($expires) <= time()) {
            return 0;
        }

        return (int) $row['value'];
    }

    /**
     * Отмечает попадание. $expiresAt — когда окно закрывается.
     */
    public function hit(string $key, int $expiresAt): void
    {
        // Имена параметров не повторяем: MySQL идёт без эмуляции подготовленных
        // выражений и один параметр дважды не принимает
        $params = [
            'key'         => $key,
            'expires'     => date('Y-m-d H:i:s', $expiresAt),
            'expires_new' => date('Y-m-d H:i:s', $expiresAt),
            'now'         => Connection::now(),
            'now_upd'     => Connection::now(),
            'now_value'   => Connection::now(),
            'now_expires' => Connection::now(),
        ];

        // Строка от прошлого, уже истёкшего окна не должна продолжать счёт.
        // Порядок присваиваний важен: value считается по прежнему expires_at
        $sql = $this->db->isSqlite()
            ? 'INSERT INTO counters (counter_key, value, expires_at, updated_at) VALUES (:key, 1, :expires, :now)
               ON CONFLICT (counter_key) DO UPDATE SET
                   value = CASE WHEN counters.expires_at IS NOT NULL AND counters.expires_at <= :now_value
                       THEN 1 ELSE counters.value + 1 END,
                   expires_at = CASE WHEN counters.expires_at IS NOT NULL AND counters.expires_at <= :now_expires
                       THEN :expires_new ELSE counters.expires_at END,
                   updated_at = :now_upd'
            : 'INSERT INTO counters (counter_key, value, expires_at, updated_at) VALUES (:key, 1, :expires, :now)
               ON DUPLICATE KEY UPDATE
                   value = CASE WHEN expires_at IS NOT NULL AND expires_at <= :now_value
                       THEN 1 ELSE value + 1 END,
                   expires_at = CASE WHEN expires_at IS NOT NULL AND expires_at <= :now_expires
                       THEN :expires_new ELSE expires_at END,
                   updated_at = :now_upd';

        $this->db->execute($sql, $params);
    }

    /**
     * Не превышен ли предел. Заодно отмечает попадание, если ещё можно.
     */
    public function attempt(string $key, int $max, int $seconds): bool
    {
        if ($this->count($key) >= $max) {
            return false;
        }

        $this->hit($key, time() + $seconds);

        return true;
    }

    /**
     * Сколько секунд осталось до конца окна.
     */
    public function retryAfter(string $key): int
    {
        $expires = (string) $this->db->value(
            'SELECT expires_at FROM counters WHERE counter_key = :key',
            ['key' => $key]
        );

        if ($expires === '') {
            return 0;
        }

        return max(0, (int) strtotime($expires) - time());
    }

    public function reset(string $key): void
    {
        $this->db->execute('DELETE FROM counters WHERE counter_key = :key', ['key' => $key]);
    }

    /**
     * Убирает счётчики, время которых прошло, — зовётся воркером.
     */
    public function cleanup(): int
    {
        return $this->db->execute(
            'DELETE FROM counters WHERE expires_at IS NOT NULL AND expires_at < :now',
            ['now' => Connection::now()]
        );
    }

    /**
     * Все счётчики — страница состояния показывает их списком.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->db->select('SELECT * FROM counters ORDER BY counter_key');
    }
}
