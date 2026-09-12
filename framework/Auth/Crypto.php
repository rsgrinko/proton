<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Auth;

use Rsgrinko\Proton\Support\Config;
use Rsgrinko\Proton\Support\ProtonException;

/**
 * Шифрование значений, которые лежат в базе (AES-256-GCM): чужие пароли,
 * секреты интеграций, токены. Ключ — APP_KEY из .env, создаётся командой
 * `php bin/proton app:key`.
 *
 * Ключа нет — значение сохраняется как есть: приложение продолжает работать,
 * но состояние сервиса об этом предупредит.
 */
final class Crypto
{
    private const CIPHER = 'aes-256-gcm';
    private const PREFIX = 'enc:v1:';

    public static function encrypt(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $key = self::key();

        if ($key === null) {
            return $value;
        }

        $iv  = random_bytes(12);
        $tag = '';

        $cipher = openssl_encrypt($value, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);

        if ($cipher === false) {
            throw new ProtonException('Не удалось зашифровать значение');
        }

        return self::PREFIX . base64_encode($iv . $tag . $cipher);
    }

    /**
     * Расшифровывает. Незашифрованные значения возвращаются как есть.
     */
    public static function decrypt(string $value): string
    {
        if ($value === '' || !str_starts_with($value, self::PREFIX)) {
            return $value;
        }

        $key = self::key();

        if ($key === null) {
            throw new ProtonException('В .env не задан APP_KEY, а в базе есть зашифрованные значения');
        }

        $data = base64_decode(substr($value, strlen(self::PREFIX)), true);

        if ($data === false || strlen($data) < 29) {
            throw new ProtonException('Зашифрованное значение повреждено');
        }

        $plain = openssl_decrypt(substr($data, 28), self::CIPHER, $key, OPENSSL_RAW_DATA, substr($data, 0, 12), substr($data, 12, 16));

        if ($plain === false) {
            throw new ProtonException('Не удалось расшифровать значение: неверный APP_KEY?');
        }

        return $plain;
    }

    public static function hasKey(): bool
    {
        return self::key() !== null;
    }

    /**
     * Новый случайный ключ для .env.
     */
    public static function generateKey(): string
    {
        return 'base64:' . base64_encode(random_bytes(32));
    }

    /**
     * Подпись строки ключом приложения — куки, токены отписки, ссылки со сроком.
     */
    public static function sign(string $value): string
    {
        $key = (string) Config::get('app.key', '');

        return $key === '' ? '' : hash_hmac('sha256', $value, $key);
    }

    /**
     * Сходится ли подпись.
     */
    public static function verify(string $value, string $signature): bool
    {
        $expected = self::sign($value);

        return $expected !== '' && hash_equals($expected, $signature);
    }

    /**
     * Бинарный ключ из настройки APP_KEY.
     */
    private static function key(): ?string
    {
        $raw = (string) Config::get('app.key', '');

        if ($raw === '') {
            return null;
        }

        if (str_starts_with($raw, 'base64:')) {
            $decoded = base64_decode(substr($raw, 7), true);

            if ($decoded !== false && strlen($decoded) === 32) {
                return $decoded;
            }
        }

        // Ключ произвольной длины приводим к 32 байтам
        return hash('sha256', $raw, true);
    }
}
