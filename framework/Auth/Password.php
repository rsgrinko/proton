<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Auth;

use Rsgrinko\Proton\Support\Config;
use Rsgrinko\Proton\Support\ProtonException;
use Rsgrinko\Proton\Support\Str;

/**
 * Пароли: храним только хеш (алгоритм по умолчанию у PHP, сейчас bcrypt).
 * Требование к длине задаётся настройкой auth.password_min.
 */
final class Password
{
    public const DEFAULT_MIN = 6;

    /**
     * Хеширует пароль, предварительно проверив его.
     */
    public static function hash(string $password): string
    {
        $error = self::check($password);

        if ($error !== null) {
            throw new ProtonException($error);
        }

        return password_hash($password, PASSWORD_DEFAULT);
    }

    public static function verify(string $password, string $hash): bool
    {
        return $hash !== '' && password_verify($password, $hash);
    }

    /**
     * Проверка пароля перед сохранением: текст ошибки или null.
     */
    public static function check(string $password): ?string
    {
        $min = self::minLength();

        if (mb_strlen($password) < $min) {
            return 'Пароль должен быть не короче ' . $min . ' символов';
        }

        return null;
    }

    public static function minLength(): int
    {
        return max(4, (int) Config::get('auth.password_min', self::DEFAULT_MIN));
    }

    /**
     * Пора ли пересчитать хеш — алгоритм по умолчанию со временем меняется.
     */
    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_DEFAULT);
    }

    /**
     * Пароль для нового пользователя, когда его не задали руками.
     */
    public static function generate(int $length = 12): string
    {
        return Str::random(max($length, self::minLength()));
    }
}
