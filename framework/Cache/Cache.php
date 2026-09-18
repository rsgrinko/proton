<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Cache;

use Rsgrinko\Proton\Database\Connection;
use Rsgrinko\Proton\Support\Config;
use Rsgrinko\Proton\Support\Profiler;

/**
 * Кэш на короткое время: тяжёлые сводки, справочники, ответы чужих API.
 *
 * Три хранилища: файлы (по умолчанию), база и память процесса. Файлы видят все
 * процессы php-fpm на машине, база — ещё и соседние машины, память живёт только
 * внутри запроса и годится тестам.
 *
 *     $stats = Cache::remember('dashboard:stats', 60, fn () => $this->heavyQuery());
 */
final class Cache
{
    public const FILE     = 'file';
    public const DATABASE = 'database';
    public const ARRAY    = 'array';

    /** @var array<string, array{value: mixed, expires: int}> Память процесса */
    private static array $memory = [];

    /**
     * Значение из кэша или свежее, если срок вышел.
     *
     * @template T
     *
     * @param callable(): T $factory
     *
     * @return T
     */
    public static function remember(string $key, int $seconds, callable $factory): mixed
    {
        if ($seconds <= 0) {
            return $factory();
        }

        $cached = self::get($key);

        if ($cached !== null) {
            /** @var T $value */
            $value = $cached;

            return $value;
        }

        $value = $factory();

        self::put($key, $value, $seconds);

        return $value;
    }

    /**
     * Значение или null, если его нет или срок вышел.
     */
    public static function get(string $key): mixed
    {
        $value = match (self::driver()) {
            self::DATABASE => self::fromDatabase($key),
            self::ARRAY    => self::fromMemory($key),
            default        => self::fromFile($key),
        };

        Profiler::cache($key, $value !== null);

        return $value;
    }

    public static function has(string $key): bool
    {
        return self::get($key) !== null;
    }

    /**
     * Кладёт значение на $seconds секунд.
     */
    public static function put(string $key, mixed $value, int $seconds): void
    {
        $expires = time() + max(1, $seconds);

        match (self::driver()) {
            self::DATABASE => self::toDatabase($key, $value, $expires),
            self::ARRAY    => self::toMemory($key, $value, $expires),
            default        => self::toFile($key, $value, $expires),
        };
    }

    /**
     * Забыть значение — например, после изменения данных, из которых оно считалось.
     */
    public static function forget(string $key): void
    {
        unset(self::$memory[$key]);

        $file = self::file($key);

        if (is_file($file)) {
            @unlink($file);
        }

        if (self::driver() === self::DATABASE) {
            Connection::instance()->execute('DELETE FROM cache WHERE cache_key = :key', ['key' => $key]);
        }
    }

    /**
     * Полная очистка — команда обслуживания и тесты.
     */
    public static function flush(): void
    {
        self::$memory = [];

        foreach ((array) glob(self::dir() . '/*.cache') as $file) {
            if (is_string($file)) {
                @unlink($file);
            }
        }

        $db = Connection::instance();

        if ($db->hasTable('cache')) {
            $db->execute('DELETE FROM cache WHERE 1 = 1');
        }
    }

    /**
     * Убирает просроченное — зовётся воркером.
     */
    public static function cleanup(): int
    {
        $removed = 0;

        foreach ((array) glob(self::dir() . '/*.cache') as $file) {
            if (!is_string($file)) {
                continue;
            }

            $payload = self::read($file);

            if ($payload === null && @unlink($file)) {
                $removed++;
            }
        }

        $db = Connection::instance();

        if ($db->hasTable('cache')) {
            $removed += $db->execute('DELETE FROM cache WHERE expires_at < :now', ['now' => Connection::now()]);
        }

        return $removed;
    }

    private static function driver(): string
    {
        return (string) Config::get('cache.driver', self::FILE);
    }

    private static function dir(): string
    {
        $dir = (string) Config::get('paths.cache', APP_ROOT . '/var/cache');

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir;
    }

    private static function file(string $key): string
    {
        return self::dir() . '/' . md5($key) . '.cache';
    }

    private static function fromFile(string $key): mixed
    {
        return self::read(self::file($key));
    }

    /**
     * Читает файл кэша: null — нет, испорчен или просрочен.
     */
    private static function read(string $file): mixed
    {
        if (!is_file($file)) {
            return null;
        }

        $payload = json_decode((string) file_get_contents($file), true);

        if (!is_array($payload) || !isset($payload['expires'])) {
            return null;
        }

        if ((int) $payload['expires'] < time()) {
            return null;
        }

        return $payload['value'] ?? null;
    }

    private static function toFile(string $key, mixed $value, int $expires): void
    {
        $file = self::file($key);

        // Пишем через временный файл: параллельный процесс не должен прочитать половину
        $temp = $file . '.' . getmypid() . '.tmp';

        $encoded = json_encode(['value' => $value, 'expires' => $expires], JSON_UNESCAPED_UNICODE);

        if ($encoded !== false && @file_put_contents($temp, $encoded) !== false) {
            @rename($temp, $file);
        }
    }

    private static function fromMemory(string $key): mixed
    {
        $item = self::$memory[$key] ?? null;

        if ($item === null || $item['expires'] < time()) {
            return null;
        }

        return $item['value'];
    }

    private static function toMemory(string $key, mixed $value, int $expires): void
    {
        self::$memory[$key] = ['value' => $value, 'expires' => $expires];
    }

    private static function fromDatabase(string $key): mixed
    {
        $db = Connection::instance();

        if (!$db->hasTable('cache')) {
            return null;
        }

        $row = $db->selectOne('SELECT value, expires_at FROM cache WHERE cache_key = :key', ['key' => $key]);

        if ($row === null || strtotime((string) $row['expires_at']) < time()) {
            return null;
        }

        $payload = json_decode((string) $row['value'], true);

        return is_array($payload) ? ($payload['value'] ?? null) : null;
    }

    private static function toDatabase(string $key, mixed $value, int $expires): void
    {
        $db = Connection::instance();

        if (!$db->hasTable('cache')) {
            return;
        }

        $encoded = (string) json_encode(['value' => $value], JSON_UNESCAPED_UNICODE);
        $until   = date('Y-m-d H:i:s', $expires);

        $sql = $db->isSqlite()
            ? 'INSERT INTO cache (cache_key, value, expires_at) VALUES (:key, :value, :expires)
               ON CONFLICT (cache_key) DO UPDATE SET value = :value_upd, expires_at = :expires_upd'
            : 'INSERT INTO cache (cache_key, value, expires_at) VALUES (:key, :value, :expires)
               ON DUPLICATE KEY UPDATE value = :value_upd, expires_at = :expires_upd';

        $db->execute($sql, [
            'key'         => $key,
            'value'       => $encoded,
            'expires'     => $until,
            'value_upd'   => $encoded,
            'expires_upd' => $until,
        ]);
    }
}
