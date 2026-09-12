<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Support;

/**
 * Список разрешённых адресов: отдельные адреса и сети CIDR, IPv4 и IPv6.
 * Пишется строкой через запятую — «127.0.0.1, 10.0.0.0/8, 2001:db8::/32».
 */
final class IpAllowlist
{
    /**
     * Подходит ли адрес под список. Пустой список не разрешает никого:
     * включённое ограничение с пустым списком — это недописанная настройка,
     * а не «пускать всех».
     */
    public static function allows(string $list, string $ip): bool
    {
        $ip = trim($ip);

        if ($ip === '') {
            return false;
        }

        foreach (explode(',', $list) as $rule) {
            $rule = trim($rule);

            if ($rule === '') {
                continue;
            }

            if (self::matches($rule, $ip)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Разбирает список в массив — панели нужно показать его по одному правилу в строке.
     *
     * @return array<int, string>
     */
    public static function parse(string $list): array
    {
        $result = [];

        foreach (preg_split('/[\s,;]+/', $list) ?: [] as $rule) {
            $rule = trim((string) $rule);

            if ($rule !== '') {
                $result[] = $rule;
            }
        }

        return $result;
    }

    /**
     * Понятно ли записано правило — проверяем на сохранении настройки.
     */
    public static function valid(string $rule): bool
    {
        if (!str_contains($rule, '/')) {
            return @inet_pton($rule) !== false;
        }

        [$network, $bits] = explode('/', $rule, 2);

        $binary = @inet_pton($network);

        if ($binary === false || !ctype_digit($bits)) {
            return false;
        }

        return (int) $bits <= strlen($binary) * 8;
    }

    private static function matches(string $rule, string $ip): bool
    {
        if (!str_contains($rule, '/')) {
            return strcasecmp($rule, $ip) === 0;
        }

        [$network, $bits] = explode('/', $rule, 2);

        $networkBinary = @inet_pton($network);
        $ipBinary      = @inet_pton($ip);

        if ($networkBinary === false || $ipBinary === false || strlen($networkBinary) !== strlen($ipBinary)) {
            return false;
        }

        $bits = (int) $bits;

        if ($bits < 0 || $bits > strlen($ipBinary) * 8) {
            return false;
        }

        $bytes = intdiv($bits, 8);
        $rest  = $bits % 8;

        if ($bytes > 0 && strncmp($networkBinary, $ipBinary, $bytes) !== 0) {
            return false;
        }

        if ($rest === 0) {
            return true;
        }

        $mask = ~((1 << (8 - $rest)) - 1) & 0xff;

        return (ord($networkBinary[$bytes]) & $mask) === (ord($ipBinary[$bytes]) & $mask);
    }
}
