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

        // С какого времени запрос считается медленным и уходит в лог, мс.
        // 0 — не писать вовсе
        'slow_ms' => Env::int('DB_SLOW_MS', 200),

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

        // Какую роль получает зарегистрировавшийся сам — по имени. Роли нет или
        // в ней есть права на управление сервисом — регистрация закрывается
        'registration_role' => Env::string('AUTH_REGISTRATION_ROLE', 'Пользователь'),

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

    // Откуда core:latest/core:diff --remote берут свежую версию ядра —
    // свой адрес у каждого проекта (gitea, зеркало на GitHub)
    'core' => [
        'repo'   => Env::string('PROTON_CORE_REPO', 'https://github.com/rsgrinko/proton'),
        'branch' => Env::string('PROTON_CORE_BRANCH', 'main'),
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

    'backup' => [
        // Куда складывать копии базы
        'path' => APP_ROOT . '/var/backups',
        // Сколько копий держать: лишние удаляются после новой
        'keep' => Env::int('BACKUP_KEEP', 7),
        // Во сколько делать копию каждый день («03:30»); пусто — не делать
        'schedule' => Env::string('BACKUP_SCHEDULE', ''),

        // Копия дополнительно уезжает на FTP, если задан хост — диск с базой
        // и диск с копиями в идеале разные, а локальный var/backups для
        // этого не годится. Пассивный режим, без TLS
        'ftp' => [
            'host'     => Env::string('FTP_HOST', ''),
            'port'     => Env::int('FTP_PORT', 21),
            'username' => Env::string('FTP_USER', ''),
            'password' => Env::string('FTP_PASSWORD', ''),
            // Каталог на сервере; пусто — корень учётной записи
            'path'     => Env::string('FTP_PATH', ''),
            'timeout'  => Env::int('FTP_TIMEOUT', 30),
        ],
    ],

    'queue' => [
        'sleep'                => Env::int('QUEUE_SLEEP', 3),
        'worker_lifetime'      => Env::int('QUEUE_WORKER_LIFETIME', 3600),
        'maintenance_interval' => Env::int('QUEUE_MAINTENANCE_INTERVAL', 300),
        'stuck_minutes'        => Env::int('QUEUE_STUCK_MINUTES', 15),
        'keep_days'            => Env::int('QUEUE_KEEP_DAYS', 7),
        // Подстраховка без отдельного демона — см. Queue\HitWorker.
        // По умолчанию выключено: это не замена настоящему воркеру
        'process_on_hit' => Env::bool('QUEUE_PROCESS_ON_HIT', false),
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
        // Сколько последних строк файла разбирает страница логов
        'tail_lines' => Env::int('LOG_TAIL_LINES', 2000),
    ],

    'audit' => [
        'keep_days' => Env::int('AUDIT_KEEP_DAYS', 180),
    ],

    'ui' => [
        'per_page' => Env::int('UI_PER_PAGE', 25),
    ],

    'export' => [
        // Разделитель CSV: Excel с русской локалью ждёт точку с запятой
        'delimiter' => Env::string('EXPORT_DELIMITER', ';'),
        // Предел строк на выгрузку: файл собирается в памяти, а человек ждёт ответа.
        // Больше — уходит в очередь, ссылка приходит уведомлением
        'max_rows' => Env::int('EXPORT_MAX_ROWS', 20000),
        // Где лежат готовые файлы фоновых выгрузок и сколько они живут
        'path'      => APP_ROOT . '/var/exports',
        'keep_days' => Env::int('EXPORT_KEEP_DAYS', 7),
    ],

    'trash' => [
        // Сколько держать удалённое, прежде чем добить окончательно.
        // 0 — не добивать никогда, корзина растёт
        'keep_days' => Env::int('TRASH_KEEP_DAYS', 30),
    ],

    'security' => [
        // Закрытые адреса проверяются на каждом заходе; выключается, если
        // блокировки делает что-то снаружи (nginx, файрвол)
        'blocklist' => Env::bool('SECURITY_BLOCKLIST', true),
        // Столько раз упёрся в лимит попыток за окно (минуты) — адрес закрывается
        'autoblock_attempts' => Env::int('SECURITY_AUTOBLOCK_ATTEMPTS', 30),
        'autoblock_window'   => Env::int('SECURITY_AUTOBLOCK_WINDOW', 60),
        'autoblock_minutes'  => Env::int('SECURITY_AUTOBLOCK_MINUTES', 60),
        // Сколько держать журнал подозрительных событий
        'keep_days' => Env::int('SECURITY_KEEP_DAYS', 60),
        // За сколько дней предупреждать владельца об истечении ключа API
        'key_expiry_notice_days' => Env::int('KEY_EXPIRY_NOTICE_DAYS', 7),
    ],

    'metrics' => [
        // Счётчики запросов в кэше: выключаются, если сбор не нужен
        'enabled' => Env::bool('METRICS_ENABLED', true),
        // С какого времени ответа запрос считается медленным, мс
        'slow_ms' => Env::int('METRICS_SLOW_MS', 1000),
        // GET /metrics для Prometheus: список адресов через запятую (CIDR тоже
        // можно), пусто — эндпоинт закрыт всем. Ключа API у сборщика обычно
        // нет, зато есть свой адрес
        'allow'   => Env::string('METRICS_ALLOW', ''),
    ],

    'monitor' => [
        // Присмотр за порогами: проверяет расписание, о беде уведомляет тех,
        // у кого право system.manage
        'enabled'         => Env::bool('MONITOR_ENABLED', true),
        'interval'        => Env::int('MONITOR_INTERVAL', 300),
        // Про одну и ту же беду не чаще раза в столько минут
        'repeat_minutes'  => Env::int('MONITOR_REPEAT_MINUTES', 60),
        'failed_jobs'     => Env::int('MONITOR_FAILED_JOBS', 10),
        'queue_backlog'   => Env::int('MONITOR_QUEUE_BACKLOG', 500),
        'errors'          => Env::int('MONITOR_ERRORS', 20),
        'free_bytes'      => Env::int('MONITOR_FREE_BYTES', 500 * 1024 * 1024),
    ],

    'import' => [
        // Строк в одном файле: больше — разбивать файл или писать свою задачу
        'max_rows' => Env::int('IMPORT_MAX_ROWS', 5000),
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
