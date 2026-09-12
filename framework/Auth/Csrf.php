<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Auth;

use Rsgrinko\Proton\Http\Response;
use Rsgrinko\Proton\Support\Config;

/**
 * Защита форм от подделки запросов с чужих сайтов.
 *
 * Токен живёт в сессии, в каждую форму подставляется скрытым полем, а прослойка
 * csrf сверяет его при любом изменяющем запросе. Кука сессии и так стоит с
 * SameSite=Lax, но полагаться на одну лишь куку не стоит: браузер может быть
 * старым, а найденная XSS без токена сразу превращается в захват аккаунта.
 *
 * Одной сессии мало: PHP выбрасывает её по своему gc_maxlifetime (по умолчанию
 * 24 минуты), а человек в это время остаётся в приложении по долгой куке
 * «запомнить меня» — и получает «форма устарела» на каждой кнопке. Поэтому токен
 * дублируется в свою куку, подписанную APP_KEY: сессия поднялась пустой — токен
 * возвращается из куки. Подпись обязательна: без неё свой токен браузеру
 * подсунул бы любой, кто умеет ставить куки на домен.
 */
final class Csrf
{
    /** Имя скрытого поля в формах и заголовка для fetch-запросов */
    public const FIELD  = '_token';
    public const HEADER = 'x-csrf-token';

    private const SESSION_KEY = 'csrf_token';

    /** Токен для окружений без сессии (консоль, тесты) */
    private static string $fallback = '';

    /**
     * Кука, которую нужно отдать вместе с ответом.
     *
     * @var array{value: string, expires: int}|null
     */
    private static ?array $pendingCookie = null;

    /**
     * Текущий токен: создаётся при первом обращении.
     */
    public static function token(): string
    {
        Auth::startSession();

        if (session_status() !== PHP_SESSION_ACTIVE) {
            if (self::$fallback === '') {
                self::$fallback = self::issue();
            }

            return self::$fallback;
        }

        if (!isset($_SESSION[self::SESSION_KEY]) || !is_string($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = self::issue();
        }

        return $_SESSION[self::SESSION_KEY];
    }

    /**
     * Совпадает ли присланный токен с нашим.
     */
    public static function check(string $sent): bool
    {
        return $sent !== '' && hash_equals(self::token(), $sent);
    }

    /**
     * Выдать новый токен — при входе и выходе, чтобы старый не пригодился.
     */
    public static function rotate(): void
    {
        // Мало забыть токен: в браузере осталась кука с ним, и следующий запрос
        // поднял бы прежний обратно. Поэтому сразу выдаём новый и шлём его в куку
        $token = bin2hex(random_bytes(16));

        unset($_COOKIE[self::cookieName()]);

        self::rememberInCookie($token);

        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION[self::SESSION_KEY] = $token;

            return;
        }

        self::$fallback = $token;
    }

    /**
     * Вернуть прежний токен.
     *
     * Нужно ровно в одном месте: при тихом входе по куке «запомнить меня».
     * Сессия там заводится заново, а страница у человека открыта со старым
     * токеном — и её отправка должна пройти.
     */
    public static function restore(string $token): void
    {
        if ($token === '') {
            return;
        }

        self::rememberInCookie($token);

        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION[self::SESSION_KEY] = $token;

            return;
        }

        self::$fallback = $token;
    }

    /**
     * Навешивает на ответ куку с токеном. Зовётся одним местом — ядром,
     * там же, где кука «запомнить меня».
     */
    public static function applyCookie(Response $response): Response
    {
        if (self::$pendingCookie === null) {
            return $response;
        }

        $cookie = self::$pendingCookie;

        self::$pendingCookie = null;

        $parts = [
            self::cookieName() . '=' . rawurlencode($cookie['value']),
            'Expires=' . gmdate('D, d M Y H:i:s', $cookie['expires']) . ' GMT',
            'Max-Age=' . max(0, $cookie['expires'] - time()),
            'Path=/',
            'HttpOnly',
            'SameSite=Lax',
        ];

        if (Auth::isHttps()) {
            $parts[] = 'Secure';
        }

        return $response->withCookie(implode('; ', $parts));
    }

    /**
     * Скрытое поле для формы.
     */
    public static function field(): string
    {
        return '<input type="hidden" name="' . self::FIELD . '" value="'
            . htmlspecialchars(self::token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
    }

    /**
     * Сбрасывает отложенную куку — нужно тестам и консоли.
     */
    public static function forget(): void
    {
        self::$pendingCookie = null;
        self::$fallback      = '';
    }

    private static function cookieName(): string
    {
        return (string) Config::get('auth.cookie_prefix', 'proton') . '_form';
    }

    /**
     * Откуда берётся токен: из своей куки, если подпись сходится, иначе новый —
     * и тогда его нужно положить в куку.
     */
    private static function issue(): string
    {
        $token = self::fromCookie();

        if ($token !== null) {
            return $token;
        }

        $token = bin2hex(random_bytes(16));

        self::rememberInCookie($token);

        return $token;
    }

    /**
     * Токен из куки, если подпись сходится. Чужая или испорченная кука — как
     * если бы её не было: выдадим новый токен.
     */
    private static function fromCookie(): ?string
    {
        $raw = (string) ($_COOKIE[self::cookieName()] ?? '');

        if ($raw === '' || !str_contains($raw, '.')) {
            return null;
        }

        [$token, $signature] = explode('.', $raw, 2);

        if (preg_match('/^[0-9a-f]{32}$/', $token) !== 1) {
            return null;
        }

        return Crypto::verify($token, $signature) ? $token : null;
    }

    /**
     * Просит положить токен в куку вместе с ответом. Без APP_KEY подписывать
     * нечем — тогда куки не будет вовсе, а токен останется жить в сессии.
     */
    private static function rememberInCookie(string $token): void
    {
        $signature = Crypto::sign($token);

        if ($signature === '') {
            return;
        }

        self::$pendingCookie = [
            'value'   => $token . '.' . $signature,
            'expires' => time() + self::lifetime(),
        ];
    }

    /**
     * Кука должна жить не меньше, чем сам вход.
     */
    private static function lifetime(): int
    {
        return max(
            (int) Config::get('auth.session_lifetime', 43200),
            (int) Config::get('auth.remember_days', 30) * 86400,
            3600
        );
    }
}
