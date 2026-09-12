<?php

declare(strict_types=1);

/**
 * Конфигурация приложения.
 *
 * Значения читаются из .env (файла нет — берутся умолчания отсюда). В самом
 * конфиге секретов нет и быть не должно: он лежит в репозитории.
 *
 * Читается один раз при старте, дальше — Config::get('db.driver').
 */

use Rsgrinko\Proton\Support\Env;

Env::load(APP_ROOT . '/.env');

return [
    'app' => [
        'name'     => Env::string('APP_NAME', 'Proton'),
        'env'      => Env::string('APP_ENV', 'local'),
        'debug'    => Env::bool('APP_DEBUG', false),
        'url'      => rtrim(Env::string('APP_URL', 'http://localhost:8000'), '/'),
        'timezone' => Env::string('APP_TIMEZONE', 'Europe/Moscow'),
        'key'      => Env::string('APP_KEY', ''),

        // Адреса своих прокси: только их заголовку X-Forwarded-For можно верить
        'trusted_proxies' => Env::string('TRUSTED_PROXIES', ''),

        // Умолчания для команды serve
        'serve_host' => Env::string('SERVE_HOST', '127.0.0.1'),
        'serve_port' => Env::int('SERVE_PORT', 8000),
    ],

    // Пункты меню — отдельным файлом, его правят чаще остального
    'menu' => require __DIR__ . '/menu.php',

    // Файлы маршрутов в порядке подключения
    'routes' => [
        'routes/web.php',
        'routes/admin.php',
        'routes/api.php',
    ],

    'db' => [
        'driver' => Env::string('DB_DRIVER', 'sqlite'),

        'sqlite' => [
            'path' => Env::string('DB_PATH', APP_ROOT . '/var/app.sqlite'),
        ],

        'mysql' => [
            'host'     => Env::string('DB_HOST', '127.0.0.1'),
            'port'     => Env::int('DB_PORT', 3306),
            'database' => Env::string('DB_DATABASE', 'proton'),
            'username' => Env::string('DB_USERNAME', 'root'),
            'password' => Env::string('DB_PASSWORD', ''),
            'charset'  => Env::string('DB_CHARSET', 'utf8mb4'),
        ],
    ],

    'auth' => [
        // Общее начало для всех кук приложения: два проекта на одном домене
        // не должны затирать друг другу сессии
        'cookie_prefix' => Env::string('AUTH_COOKIE_PREFIX', 'proton'),

        'session_lifetime'   => Env::int('AUTH_SESSION_LIFETIME', 43200),
        'remember_days'      => Env::int('AUTH_REMEMBER_DAYS', 30),
        'password_min'       => Env::int('AUTH_PASSWORD_MIN', 6),
        'max_attempts'       => Env::int('AUTH_MAX_ATTEMPTS', 10),
        'attempts_window'    => Env::int('AUTH_ATTEMPTS_WINDOW', 900),
        'sessions_keep_days' => Env::int('AUTH_SESSIONS_KEEP_DAYS', 60),

        // Открыта ли регистрация и нужно ли подтверждать почту
        'registration' => Env::bool('AUTH_REGISTRATION', true),
        'verify_email' => Env::bool('AUTH_VERIFY_EMAIL', false),

        // Сколько живёт ссылка подтверждения и сброса пароля, секунды
        'link_lifetime' => Env::int('AUTH_LINK_LIFETIME', 86400),
    ],

    'mail' => [
        // mail | smtp | mailer | log | null
        'driver'     => Env::string('MAIL_DRIVER', 'mail'),
        'from_email' => Env::string('MAIL_FROM', ''),
        'from_name'  => Env::string('MAIL_FROM_NAME', Env::string('APP_NAME', 'Proton')),

        'smtp' => [
            'host'          => Env::string('SMTP_HOST', '127.0.0.1'),
            'port'          => Env::int('SMTP_PORT', 587),
            'username'      => Env::string('SMTP_USER', ''),
            'password'      => Env::string('SMTP_PASSWORD', ''),
            // '' | ssl (465) | tls (587)
            'encryption'    => Env::string('SMTP_ENCRYPTION', 'tls'),
            'timeout'       => Env::int('SMTP_TIMEOUT', 30),
            'verify_peer'   => Env::bool('SMTP_VERIFY_PEER', true),
            'helo'          => Env::string('SMTP_HELO', ''),
            'session_limit' => Env::int('SMTP_SESSION_LIMIT', 100),
        ],

        // Отправка через внешний почтовый сервис по HTTP API
        'service' => [
            'url'     => rtrim(Env::string('MAIL_SERVICE_URL', ''), '/'),
            'key'     => Env::string('MAIL_SERVICE_KEY', ''),
            'timeout' => Env::int('MAIL_SERVICE_TIMEOUT', 10),
        ],
    ],

    // Проверка TLS при обращении к чужим сервисам: почта, вебхуки, интеграции.
    // Пусто — PHP берёт хранилище системы; на Windows его обычно нет вовсе,
    // и тогда сюда прописывается путь к cacert.pem
    'http' => [
        'ca_bundle'   => Env::string('HTTP_CA_BUNDLE', ''),
        'timeout'     => Env::int('HTTP_TIMEOUT', 10),
        // Выключать проверку — значит отдать ключи интеграций тому, кто встанет
        // посередине. Правильный путь — прописать ca_bundle
        'verify_peer' => Env::bool('HTTP_VERIFY_PEER', true),
    ],

    'notifications' => [
        // Прочитанные уведомления старше срока убирает воркер; непрочитанные
        // не трогаются вовсе — человек их ещё не видел
        'keep_days' => Env::int('NOTIFICATIONS_KEEP_DAYS', 90),
    ],

    'webhooks' => [
        'enabled'   => Env::bool('WEBHOOKS_ENABLED', true),
        'timeout'   => Env::int('WEBHOOKS_TIMEOUT', 10),
        'attempts'  => Env::int('WEBHOOKS_ATTEMPTS', 5),
        // Подписка, не ответившая столько раз подряд, отключается сама;
        // 0 — не отключать никогда
        'disable_after' => Env::int('WEBHOOKS_DISABLE_AFTER', 20),
        'keep_days'     => Env::int('WEBHOOKS_KEEP_DAYS', 14),
        // Своя очередь: чужой сервер отвечает долго, а письма ждать не должны.
        // Пусто — посылки идут общей очередью
        'queue'         => Env::string('WEBHOOKS_QUEUE', 'webhooks'),
    ],

    'queue' => [
        'sleep'                => Env::int('QUEUE_SLEEP', 3),
        'worker_lifetime'      => Env::int('QUEUE_WORKER_LIFETIME', 3600),
        'maintenance_interval' => Env::int('QUEUE_MAINTENANCE_INTERVAL', 300),
        'stuck_minutes'        => Env::int('QUEUE_STUCK_MINUTES', 15),
        'keep_days'            => Env::int('QUEUE_KEEP_DAYS', 7),
    ],

    'cache' => [
        // file | database | array
        'driver' => Env::string('CACHE_DRIVER', 'file'),
    ],

    'files' => [
        'max_size' => Env::int('FILES_MAX_SIZE', 5 * 1024 * 1024),

        // Пустой список — принимаем что угодно; так делать не стоит
        'allowed' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'pdf', 'txt', 'csv', 'zip', 'doc', 'docx', 'xls', 'xlsx'],

        'allowed_mime' => [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml',
            'application/pdf', 'text/plain', 'text/csv', 'application/zip',
            'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ],
    ],

    'log' => [
        // debug | info | warning | error
        'level'     => Env::string('LOG_LEVEL', 'info'),
        'keep_days' => Env::int('LOG_KEEP_DAYS', 30),
    ],

    'audit' => [
        'keep_days' => Env::int('AUDIT_KEEP_DAYS', 180),
    ],

    'ui' => [
        'per_page' => Env::int('UI_PER_PAGE', 25),
    ],

    // Настройки самого приложения, а не ядра. Эту правят из панели —
    // см. config/settings.php
    'notes' => [
        'per_user' => Env::int('NOTES_PER_USER', 0),
    ],

    'paths' => [
        'log'     => APP_ROOT . '/var/log',
        'tmp'     => APP_ROOT . '/var/tmp',
        'cache'   => APP_ROOT . '/var/cache',
        'storage' => APP_ROOT . '/var/storage',
    ],
];
