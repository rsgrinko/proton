<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Support;

/**
 * Конфигурация приложения: один раз читаем config/config.php, потом берём значения
 * по «точечному» пути — Config::get('db.driver').
 */
final class Config
{
    /** @var array<string, mixed> */
    private static array $items = [];

    private static bool $loaded = false;

    /**
     * Читает config/config.php. Второй вызов ничего не делает.
     */
    public static function load(?string $file = null): void
    {
        if (self::$loaded) {
            return;
        }

        $file = $file ?? APP_ROOT . '/config/config.php';
        $data = is_file($file) ? require $file : [];

        self::$items  = is_array($data) ? $data : [];
        self::$loaded = true;

        self::applyTimezone();
    }

    /**
     * Значение по пути вида 'mail.driver'. Пути нет — вернём $default.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        self::load();

        $value = self::$items;

        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }

            $value = $value[$part];
        }

        return $value;
    }

    /**
     * Подменить значение в рантайме — нужно тестам и командам.
     */
    public static function set(string $key, mixed $value): void
    {
        self::load();

        $reference = &self::$items;

        foreach (explode('.', $key) as $part) {
            if (!isset($reference[$part]) || !is_array($reference[$part])) {
                $reference[$part] = [];
            }

            $reference = &$reference[$part];
        }

        $reference = $value;
    }

    /**
     * Вся конфигурация целиком.
     *
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        self::load();

        return self::$items;
    }

    /**
     * Часовой пояс из APP_TIMEZONE.
     *
     * Пояс обязан быть один у веб-части, воркера и консольных команд: время в базе
     * пишется через date(), и запуск команды с машины в другом поясе сдвинет всё,
     * что откладывается на будущее, — отложенная задача уйдёт раньше срока.
     */
    private static function applyTimezone(): void
    {
        $timezone = trim((string) (self::$items['app']['timezone'] ?? ''));

        if ($timezone === '' || !in_array($timezone, timezone_identifiers_list(), true)) {
            return;
        }

        date_default_timezone_set($timezone);
    }
}
