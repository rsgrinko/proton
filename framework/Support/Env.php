<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Support;

/**
 * Чтение файла .env.
 *
 * Понимает:
 *   KEY=value
 *   KEY="значение с пробелами"
 *   KEY='как есть'
 *   # комментарии и пустые строки
 */
final class Env
{
    /** @var array<string, string> */
    private static array $values = [];

    private static bool $loaded = false;

    /**
     * Загружает файл. Повторный вызов ничего не делает, если не передан $force.
     */
    public static function load(string $file, bool $force = false): void
    {
        if (self::$loaded && !$force) {
            return;
        }

        self::$loaded = true;

        if (!is_file($file) || !is_readable($file)) {
            return;
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);

            $key   = trim($key);
            $value = trim($value);

            // Снимаем обрамляющие кавычки
            $length = strlen($value);

            if ($length >= 2) {
                $first = $value[0];
                $last  = $value[$length - 1];

                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            if ($key !== '') {
                self::$values[$key] = $value;
            }
        }
    }

    /**
     * Значение переменной. Приоритет: окружение процесса, затем .env, затем умолчание.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $raw = getenv($key);

        if ($raw === false) {
            $raw = self::$values[$key] ?? null;
        }

        if ($raw === null || $raw === '') {
            return $default;
        }

        return match (strtolower((string) $raw)) {
            'true', '(true)'   => true,
            'false', '(false)' => false,
            'null', '(null)'   => null,
            default            => $raw,
        };
    }

    public static function string(string $key, string $default = ''): string
    {
        $value = self::get($key, null);

        if ($value === null) {
            return $default;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key, null);

        return $value === null ? $default : (int) $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key, null);

        if ($value === null) {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) $value), ['1', 'yes', 'on', 'true'], true);
    }

    /**
     * Все прочитанные значения — нужно консоли, которая правит .env.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        return self::$values;
    }
}
