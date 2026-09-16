<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Auth;

use Rsgrinko\Proton\Access\Viewer;
use Rsgrinko\Proton\Http\Response;
use Rsgrinko\Proton\Models\RememberToken;
use Rsgrinko\Proton\Models\BlockedIp;
use Rsgrinko\Proton\Models\SecurityEvent;
use Rsgrinko\Proton\Models\User;
use Rsgrinko\Proton\Models\UserSession;
use Rsgrinko\Proton\RateLimit\RateLimiter;
use Rsgrinko\Proton\Support\ClientIp;
use Rsgrinko\Proton\Support\Config;

/**
 * Авторизация: сессия, текущий пользователь, «запомнить меня» и защита от подбора.
 *
 * Сессия обычная, на куке с SameSite=Lax — чужой сайт не отправит форму от имени
 * вошедшего. С галкой «запомнить меня» рядом с сессией живёт долгая кука; пароля
 * в ней нет, это пара selector:validator из remember_tokens.
 *
 * Куки Auth не ставит сам: заголовки есть только у ответа, поэтому готовая кука
 * складывается в $pendingCookie, а навешивает её ядро через applyCookies() —
 * одно место на все ветки, включая страницу ошибки.
 */
final class Auth
{
    /** Ключ пользователя в сессии */
    private const SESSION_KEY = 'auth_user';

    /** Ключ id того, за кого сейчас смотрят — «войти под пользователем» из панели */
    private const IMPERSONATE_KEY = 'auth_impersonate';

    /** @var User|null|false false — ещё не смотрели. Эффективный пользователь: во время
     *  подмены это тот, за кого смотрят, а не тот, кто нажал «войти под пользователем» */
    private static User|null|false $cached = false;

    /** @var User|null|false false — ещё не смотрели. Настоящий вошедший, без подмены */
    private static User|null|false $realCached = false;

    /**
     * @var array{value: string, expires: int}|null
     */
    private static ?array $pendingCookie = null;

    // --- Сессия --------------------------------------------------------------

    /**
     * Стартует сессию. Вызывать до любого вывода.
     */
    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE || PHP_SAPI === 'cli') {
            return;
        }

        // Сборщик мусора PHP по умолчанию выбрасывает сессию через 24 минуты,
        // а мы обещаем auth.session_lifetime: без этого человека выкидывало
        // сильно раньше обещанного
        $lifetime = (int) Config::get('auth.session_lifetime', 43200);

        if ($lifetime > (int) ini_get('session.gc_maxlifetime')) {
            @ini_set('session.gc_maxlifetime', (string) $lifetime);
        }

        session_name((string) Config::get('auth.cookie_prefix', 'proton') . '_session');

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => self::isHttps(),
        ]);

        @session_start();
    }

    /**
     * Текущий пользователь или null — с поправкой на «войти под пользователем»:
     * во время подмены это тот, за кого смотрят, а не тот, кто её включил.
     * Данные перечитываются из базы, поэтому отключённый пользователь теряет
     * доступ сразу, а не после выхода.
     */
    public static function user(): ?User
    {
        if (self::$cached !== false) {
            return self::$cached;
        }

        $real = self::resolveReal();

        if ($real === null) {
            return self::$cached = null;
        }

        $targetId = (int) ($_SESSION[self::IMPERSONATE_KEY] ?? 0);

        if ($targetId > 0) {
            $target = User::find($targetId);

            if ($target !== null && $target->isActive()) {
                return self::$cached = $target;
            }

            // Подменяемого удалили или отключили, пока за него смотрели —
            // тихо возвращаемся к себе, а не роняем страницу
            unset($_SESSION[self::IMPERSONATE_KEY]);
        }

        return self::$cached = $real;
    }

    /**
     * Настоящий вошедший, без учёта подмены — тот, кто нажал «войти под
     * пользователем» и кому вернётся сессия после «выйти из-под пользователя».
     */
    public static function realUser(): ?User
    {
        if (self::$realCached !== false) {
            return self::$realCached;
        }

        return self::$realCached = self::resolveReal();
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    /**
     * Настоящая сессия как она есть, без поправки на подмену — раньше это было
     * тело user(). Здесь же живут проверки живучести сессии (устройство,
     * «выйти на остальных», срок), и они всегда идут по реальному id: во время
     * подмены сеанс всё равно принадлежит администратору, а не тому, кого он
     * сейчас изображает.
     */
    private static function resolveReal(): ?User
    {
        self::startSession();

        $id = (int) ($_SESSION[self::SESSION_KEY]['id'] ?? 0);

        if ($id === 0) {
            // Сессии нет — может, человек просил себя запомнить
            return self::fromRememberCookie();
        }

        // Слишком долго не заходил — просим войти заново
        $lifetime = (int) Config::get('auth.session_lifetime', 43200);
        $seen     = (int) ($_SESSION[self::SESSION_KEY]['seen'] ?? 0);

        if ($lifetime > 0 && $seen > 0 && time() - $seen > $lifetime) {
            // Сессию закрываем, но долгую куку не трогаем: её гасит только явный
            // выход. Иначе «запомнить меня» работало бы лишь до конца сессии
            self::clearSession();

            return self::fromRememberCookie();
        }

        $user = User::find($id);

        if ($user === null || !$user->isActive()) {
            self::logout();

            return null;
        }

        // Сеанс могли завершить с другого устройства
        if (!self::sessionAlive($user)) {
            self::clearSession();
            self::forgetRememberCookie();

            return null;
        }

        $_SESSION[self::SESSION_KEY]['seen'] = time();

        $sid = (string) ($_SESSION[self::SESSION_KEY]['sid'] ?? '');

        if ($sid !== '') {
            UserSession::touch($sid, self::ip());
        }

        return $user;
    }

    public static function id(): int
    {
        return self::user()?->id() ?? 0;
    }

    /**
     * Кто смотрит: права и область видимости.
     */
    public static function viewer(): Viewer
    {
        $user = self::user();

        return $user === null ? Viewer::guest() : Viewer::fromUser($user);
    }

    // --- Вход и выход --------------------------------------------------------

    /**
     * Попытка входа. Возвращает пользователя либо текст ошибки.
     *
     * @return array{user: User|null, error: string}
     */
    public static function attempt(string $credential, string $password, string $ip): array
    {
        $limiter = new RateLimiter();
        $key     = 'login:' . $ip;
        $max     = max(1, (int) Config::get('auth.max_attempts', 10));
        $window  = max(60, (int) Config::get('auth.attempts_window', 900));

        if ($limiter->count($key) >= $max) {
            SecurityEvent::record(SecurityEvent::LOGIN_BLOCKED, $credential);

            // Перебор продолжается после исчерпанного лимита — закрываем адрес
            self::blockIfPersistent($ip);

            return ['user' => null, 'error' => 'Слишком много попыток входа. Попробуйте позже'];
        }

        if ($credential === '' || $password === '') {
            return ['user' => null, 'error' => 'Введите логин и пароль'];
        }

        $user = User::findByCredential($credential);

        if ($user === null || !$user->verifyPassword($password) || !$user->isActive()) {
            $limiter->hit($key, time() + $window);

            SecurityEvent::record(SecurityEvent::LOGIN_FAILED, $credential);

            return ['user' => null, 'error' => 'Неверный логин или пароль'];
        }

        // Алгоритм хеширования со временем меняется — пересчитываем на входе,
        // другого места, где виден открытый пароль, нет
        if (Password::needsRehash((string) $user->raw('password_hash'))) {
            $user->forceFill(['password_hash' => Password::hash($password)])->save();
        }

        $limiter->reset($key);

        return ['user' => $user, 'error' => ''];
    }

    /**
     * Закрыть адрес, если перебор не прекращается. Порог и срок задаются
     * настройками SECURITY_*; ноль в пороге выключает автоблокировку.
     */
    private static function blockIfPersistent(string $ip): void
    {
        $threshold = (int) Config::get('security.autoblock_attempts', 30);

        if ($threshold <= 0 || $ip === '') {
            return;
        }

        $window = max(1, (int) Config::get('security.autoblock_window', 60));

        if (SecurityEvent::countFor($ip, SecurityEvent::LOGIN_BLOCKED, $window) < $threshold) {
            return;
        }

        BlockedIp::block($ip, 'перебор паролей', max(1, (int) Config::get('security.autoblock_minutes', 60)));
    }

    /**
     * Записывает пользователя в сессию.
     *
     * @param bool $remember просили запомнить — заводим долгую куку
     */
    public static function login(User $user, bool $remember = false, bool $rotateCsrf = true): void
    {
        self::startSession();

        // Новый идентификатор сессии — чтобы чужой заранее подсунутый не подошёл
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        // Токен форм тоже меняем: старый мог быть подсмотрен до входа.
        // Не меняем только при тихом входе по долгой куке
        if ($rotateCsrf) {
            Csrf::rotate();
        }

        // Новый настоящий вход — прежняя подмена (если она была в этой сессии
        // до перезахода) больше не имеет смысла
        unset($_SESSION[self::IMPERSONATE_KEY]);
        self::$realCached = false;

        $_SESSION[self::SESSION_KEY] = [
            'id'      => $user->id(),
            'login'   => (string) $user->login,
            'seen'    => time(),
            // Когда сессия началась: по этой отметке её гасит «выйти на остальных»
            'started' => time(),
            // Из какого токена «запомнить меня» она выросла
            'remember' => '',
            // Своё имя сеанса: по нему он значится в описи устройств
            'sid' => bin2hex(random_bytes(16)),
        ];

        self::$cached = $user;

        $days     = self::rememberDays();
        $selector = '';

        if ($remember && $days > 0) {
            $cookie   = RememberToken::issue($user->id(), $days, self::ip(), self::agent());
            $selector = RememberToken::selectorOf($cookie);

            $_SESSION[self::SESSION_KEY]['remember'] = $selector;

            self::$pendingCookie = ['value' => $cookie, 'expires' => time() + $days * 86400];
        }

        // Опись устройств ведём для всех сессий, а не только для «запомнить меня»:
        // вход с телефона без галки иначе не значился бы нигде
        UserSession::open($user->id(), (string) $_SESSION[self::SESSION_KEY]['sid'], self::ip(), self::agent(), $selector);

        $user->markLogin(self::ip());
    }

    // --- Вход под пользователем ("impersonation") ----------------------------

    /**
     * Войти под чужой учёткой, оставаясь собой для «выйти обратно». Проверку
     * прав (кому вообще можно жать эту кнопку) делает право `users.impersonate`
     * у маршрута — сюда приходит уже разрешённый вызов.
     *
     * Не трогает сессию входа как таковую (id, sid, remember-селектор) — там
     * по-прежнему настоящий администратор: `sessionAlive()`, долгая кука и опись
     * устройств продолжают проверяться по нему. Подменяется только то, кого
     * возвращает `user()`.
     *
     * Возвращает false, если сессии нет или подменять некого (самого себя).
     */
    public static function impersonate(User $target): bool
    {
        $real = self::realUser();

        if ($real === null || $real->id() === $target->id()) {
            return false;
        }

        self::startSession();

        $_SESSION[self::IMPERSONATE_KEY] = $target->id();

        self::$cached = false;

        return true;
    }

    /**
     * Вернуться в свою сессию. Без активной подмены — просто ничего не делает.
     */
    public static function stopImpersonating(): void
    {
        self::startSession();

        unset($_SESSION[self::IMPERSONATE_KEY]);

        self::$cached = false;
    }

    public static function isImpersonating(): bool
    {
        self::startSession();

        return (int) ($_SESSION[self::IMPERSONATE_KEY] ?? 0) > 0;
    }

    public static function logout(): void
    {
        self::startSession();

        $sid = (string) ($_SESSION[self::SESSION_KEY]['sid'] ?? '');

        if ($sid !== '') {
            UserSession::close($sid);
        }

        // Токен этого браузера гасим, чужие устройства того же человека не трогаем
        $cookie = self::rememberCookie();

        if ($cookie !== '') {
            $found = RememberToken::match($cookie);

            $found['token']?->forceDelete();
        }

        self::forgetRememberCookie();
        self::clearSession();

        Csrf::rotate();
    }

    /**
     * Оставляет текущую сессию в живых после того, как погасили все.
     *
     * Отметка в базе одна на пользователя и гасит в том числе тот браузер, из
     * которого нажали кнопку, — а выкидывать себя, выходя «на остальных
     * устройствах», незачем.
     */
    public static function keepThisSession(): void
    {
        self::startSession();

        if (isset($_SESSION[self::SESSION_KEY])) {
            // На секунду вперёд: время отмены хранится с точностью до секунды,
            // и сессия с той же секундой считалась бы погашенной
            $_SESSION[self::SESSION_KEY]['started'] = time() + 1;
        }
    }

    /**
     * Сбрасывает запомненного пользователя — нужно тестам и консоли.
     */
    public static function forget(): void
    {
        self::$cached        = false;
        self::$realCached    = false;
        self::$pendingCookie = null;
    }

    /**
     * Подставляет пользователя без сессии — нужно тестам и внутренним вызовам.
     */
    public static function actAs(?User $user): void
    {
        self::$cached     = $user;
        self::$realCached = $user;
    }

    // --- Куки ----------------------------------------------------------------

    /**
     * Навешивает на ответ куку «запомнить меня», если её нужно поставить или убрать.
     */
    public static function applyCookies(Response $response): Response
    {
        if (self::$pendingCookie === null) {
            return $response;
        }

        $cookie = self::$pendingCookie;

        self::$pendingCookie = null;

        $parts = [
            self::rememberCookieName() . '=' . rawurlencode($cookie['value']),
            'Expires=' . gmdate('D, d M Y H:i:s', $cookie['expires']) . ' GMT',
            'Max-Age=' . max(0, $cookie['expires'] - time()),
            'Path=/',
            'HttpOnly',
            'SameSite=Lax',
        ];

        if (self::isHttps()) {
            $parts[] = 'Secure';
        }

        return $response->withCookie(implode('; ', $parts));
    }

    /**
     * Сколько дней жить долгой куке. Ноль — галку «запомнить меня» не показываем.
     */
    public static function rememberDays(): int
    {
        return max(0, (int) Config::get('auth.remember_days', 30));
    }

    public static function rememberCookieName(): string
    {
        return (string) Config::get('auth.cookie_prefix', 'proton') . '_remember';
    }

    /**
     * Селектор долгой куки этого браузера — по нему список сеансов узнаёт
     * текущее устройство; секрет для этого не нужен.
     */
    public static function currentSelector(): string
    {
        return RememberToken::selectorOf(self::rememberCookie());
    }

    /**
     * Имя текущего сеанса в описи устройств.
     */
    public static function currentSession(): string
    {
        self::startSession();

        return (string) ($_SESSION[self::SESSION_KEY]['sid'] ?? '');
    }

    /**
     * Соединение защищено — куки помечаем Secure.
     */
    public static function isHttps(): bool
    {
        if (($_SERVER['HTTPS'] ?? '') !== '' && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }

        return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    // --- Внутреннее ----------------------------------------------------------

    /**
     * Вход по долгой куке: сессия кончилась, но человек просил себя запомнить.
     */
    private static function fromRememberCookie(): ?User
    {
        $cookie = self::rememberCookie();

        if ($cookie === '' || self::rememberDays() === 0) {
            return null;
        }

        $found = RememberToken::match($cookie);
        $token = $found['token'];

        if ($token === null) {
            // Куку с несуществующим или подделанным токеном убираем, чтобы она
            // не ходила с каждым запросом
            self::forgetRememberCookie();

            return null;
        }

        $user = User::find((int) $token->raw('user_id'));

        if ($user === null || !$user->isActive()) {
            RememberToken::query()->where('user_id', (int) $token->raw('user_id'))->forceDelete();
            self::forgetRememberCookie();

            return null;
        }

        // Сессию поднимаем заново, но токен форм сохраняем: страница в браузере
        // открыта со старым токеном, и её отправка должна проходить
        $formToken = Csrf::token();

        self::login($user, false, false);

        $_SESSION[self::SESSION_KEY]['remember'] = (string) $token->raw('selector');

        $sid = (string) ($_SESSION[self::SESSION_KEY]['sid'] ?? '');

        if ($sid !== '') {
            UserSession::attach($sid, (string) $token->raw('selector'));
        }

        Csrf::restore($formToken);

        // Пришли с прежним validator в окно снисхождения: это соседний запрос той
        // же страницы, токен уже сменил первый из них. Второй раз не меняем —
        // иначе браузер получит две разные куки и одна из них устареет
        if ($found['grace']) {
            return $user;
        }

        self::$pendingCookie = [
            'value'   => $token->rotate(self::ip(), self::agent()),
            'expires' => strtotime((string) $token->raw('expires_at')) ?: time() + self::rememberDays() * 86400,
        ];

        return $user;
    }

    /**
     * Не завершили ли этот сеанс с другого устройства.
     *
     * Две разные отмены, и обе нужны: users.sessions_from гасит все сессии разом
     * (кнопка «выйти на остальных», смена пароля), а строка описи гасит именно
     * это устройство.
     */
    private static function sessionAlive(User $user): bool
    {
        $session = (array) ($_SESSION[self::SESSION_KEY] ?? []);
        $border  = trim((string) $user->raw('sessions_from'));

        if ($border !== '') {
            $started = (int) ($session['started'] ?? 0);

            // Отметки о начале нет — сессия старше самого механизма, доверять нечему
            if ($started === 0 || $started <= (int) strtotime($border)) {
                return false;
            }
        }

        $selector = (string) ($session['remember'] ?? '');

        if ($selector !== '' && !RememberToken::query()->where('selector', $selector)->exists()) {
            return false;
        }

        $sid = (string) ($session['sid'] ?? '');

        if ($sid !== '') {
            $row = UserSession::bySid($sid);

            // Записи нет — опись подчистил воркер: выкидывать за это нельзя
            if ($row !== null && $row->revoked()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Закрывает сессию, не трогая долгую куку: этим «сессия протухла» отличается
     * от «человек нажал выйти».
     *
     * Токен форм здесь не меняем: сессию мог унести сборщик мусора, а человек
     * остаётся в приложении по долгой куке — открытая у него страница должна
     * отправляться.
     */
    private static function clearSession(): void
    {
        unset($_SESSION[self::SESSION_KEY], $_SESSION[self::IMPERSONATE_KEY]);

        self::$cached     = null;
        self::$realCached = null;

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    private static function rememberCookie(): string
    {
        return trim((string) ($_COOKIE[self::rememberCookieName()] ?? ''));
    }

    private static function forgetRememberCookie(): void
    {
        unset($_COOKIE[self::rememberCookieName()]);

        self::$pendingCookie = ['value' => '', 'expires' => time() - 86400];
    }

    /**
     * Адрес клиента, а не прокси: иначе в описи устройств все выглядят одинаково.
     */
    private static function ip(): string
    {
        return ClientIp::fromGlobals();
    }

    /**
     * Строка браузера: отличить телефон от рабочего компьютера по адресу нельзя —
     * дома он один и тот же.
     */
    private static function agent(): string
    {
        return trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    }

}
