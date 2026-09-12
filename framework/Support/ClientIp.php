<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Support;

/**
 * Настоящий адрес клиента, когда приложение стоит за прокси.
 *
 * За nginx REMOTE_ADDR — это адрес самого прокси, а клиент приезжает в
 * X-Forwarded-For. Верить заголовку без оглядки нельзя: его пришлёт кто угодно,
 * и тогда ограничение по адресам обходится одной строкой в запросе. Поэтому
 * заголовку верим, только когда запрос пришёл от своего прокси (TRUSTED_PROXIES),
 * и берём из цепочки последний адрес, который не наш: всё, что левее, дописал клиент.
 */
final class ClientIp
{
    /**
     * Адрес из суперглобальных — для тех, у кого нет объекта запроса.
     */
    public static function fromGlobals(): string
    {
        return self::resolve(
            (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')
        );
    }

    /**
     * Адрес клиента по адресу соединения и заголовку.
     */
    public static function resolve(string $remote, string $forwarded): string
    {
        $remote  = self::normalize($remote);
        $trusted = (string) Config::get('app.trusted_proxies', '');

        // Прокси не объявлен или запрос пришёл не от него — заголовку верить нечему
        if ($trusted === '' || !IpAllowlist::allows($trusted, $remote)) {
            return $remote;
        }

        $chain = trim($forwarded);

        if ($chain === '') {
            return $remote;
        }

        foreach (array_reverse(explode(',', $chain)) as $candidate) {
            $candidate = self::normalize($candidate);

            if ($candidate === '' || @inet_pton($candidate) === false) {
                continue;
            }

            if (!IpAllowlist::allows($trusted, $candidate)) {
                return $candidate;
            }
        }

        // В цепочке одни свои прокси — настоящего клиента там нет
        return $remote;
    }

    /**
     * Приводит запись к голому адресу: убирает порт и скобки, разворачивает
     * IPv4, завёрнутый в IPv6 (::ffff:10.0.0.5).
     */
    public static function normalize(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        if (str_starts_with($value, '[')) {
            $close = strpos($value, ']');

            $value = $close === false ? substr($value, 1) : substr($value, 1, $close - 1);
        } elseif (substr_count($value, ':') === 1) {
            // Одно двоеточие — это IPv4 с портом; у голого IPv6 их всегда больше
            $value = substr($value, 0, (int) strpos($value, ':'));
        }

        if (stripos($value, '::ffff:') === 0) {
            $plain = substr($value, 7);

            if (@inet_pton($plain) !== false && !str_contains($plain, ':')) {
                return $plain;
            }
        }

        return $value;
    }
}
