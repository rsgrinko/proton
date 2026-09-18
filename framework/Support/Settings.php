<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Support;

use Rsgrinko\Proton\Auth\Crypto;
use Rsgrinko\Proton\Models\Setting;
use Throwable;

/**
 * Настройки, которые меняются из панели, — поверх `.env`.
 *
 * Правило простое: `.env` задаёт то, без чего приложение не поднимется (база,
 * ключ, адрес), а здесь живёт то, что админ меняет на ходу: название, открыта ли
 * регистрация, каким драйвером шлём письма, сколько записей на странице.
 *
 * Значение ищется в базе, и только если его там нет — берётся из `.env`. Поэтому
 * «сбросить» настройку — это удалить строку из базы, а не записать в неё то же,
 * что в `.env`: иначе правка `.env` потом ничего бы не меняла.
 *
 * Какие настройки вообще есть — решает код (как с правами и событиями вебхуков):
 * ядро объявляет свои здесь, приложение дописывает в `config/settings.php`.
 * В базе лежат только значения.
 *
 *     Settings::register('shop.currency', 'Валюта', Settings::STRING, 'Магазин');
 *
 * Настройки применяются один раз за процесс — `Settings::apply()` зовёт ядро
 * (веб) и консоль. Долгоживущему воркеру правку подхватит перезапуск: он и так
 * нужен после выкладки кода.
 */
final class Settings
{
    /** Типы значений: от типа зависят и поле в форме, и проверка */
    public const STRING = 'string';
    public const TEXT   = 'text';
    public const INT    = 'int';
    public const BOOL   = 'bool';
    public const SELECT = 'select';
    public const SECRET = 'secret';

    /** Начало ключа в таблице settings: рядом лежат рантаймовые отметки ядра */
    public const PREFIX = 'config:';

    /**
     * Настройки ядра по разделам: путь в конфиге => описание.
     *
     * `env` — имя переменной в `.env`, на неё ложится значение при выгрузке
     * (см. exportEnv()). Настроек базы (`db.*`) и ключа приложения (`app.key`)
     * здесь нарочно нет: их правка требует перезапуска и может оставить
     * приложение без доступа к данным — это работа с `.env` и сервером,
     * а не кнопка в браузере.
     *
     * @var array<string, array<string, array{label: string, type: string, env?: string, options?: array<string, string>, hint?: string}>>
     */
    private const CORE = [
        'Приложение' => [
            'app.name'     => ['label' => 'Название', 'type' => self::STRING, 'env' => 'APP_NAME'],
            'app.env'      => ['label' => 'Окружение', 'type' => self::STRING, 'env' => 'APP_ENV', 'hint' => 'production блокирует migrate:fresh'],
            'app.debug'    => ['label' => 'Отладка (APP_DEBUG)', 'type' => self::BOOL, 'env' => 'APP_DEBUG', 'hint' => 'панель отладки внизу страницы, подробные ошибки'],
            'app.url'      => ['label' => 'Адрес приложения', 'type' => self::STRING, 'env' => 'APP_URL'],
            'app.timezone' => ['label' => 'Часовой пояс', 'type' => self::STRING, 'env' => 'APP_TIMEZONE'],
            'app.trusted_proxies' => ['label' => 'Доверенные прокси', 'type' => self::STRING, 'env' => 'TRUSTED_PROXIES', 'hint' => 'адреса через запятую, можно CIDR'],
            'app.serve_host' => ['label' => 'Хост для serve', 'type' => self::STRING, 'env' => 'SERVE_HOST'],
            'app.serve_port' => ['label' => 'Порт для serve', 'type' => self::INT, 'env' => 'SERVE_PORT'],
            'ui.per_page'  => ['label' => 'Записей на странице', 'type' => self::INT, 'env' => 'UI_PER_PAGE', 'hint' => 'от 5 до 200'],
        ],
        'Вход и регистрация' => [
            'auth.registration'      => ['label' => 'Открыта регистрация', 'type' => self::BOOL, 'env' => 'AUTH_REGISTRATION'],
            'auth.verify_email'      => ['label' => 'Требовать подтверждение почты', 'type' => self::BOOL, 'env' => 'AUTH_VERIFY_EMAIL'],
            'auth.max_attempts'      => ['label' => 'Попыток входа до блокировки', 'type' => self::INT, 'env' => 'AUTH_MAX_ATTEMPTS'],
            'auth.attempts_window'   => ['label' => 'Окно попыток входа, сек', 'type' => self::INT, 'env' => 'AUTH_ATTEMPTS_WINDOW'],
            'auth.cookie_prefix'     => ['label' => 'Начало имени кук', 'type' => self::STRING, 'env' => 'AUTH_COOKIE_PREFIX'],
            'auth.session_lifetime'  => ['label' => 'Жизнь сессии, сек', 'type' => self::INT, 'env' => 'AUTH_SESSION_LIFETIME'],
            'auth.remember_days'     => ['label' => 'Дней помнить вход', 'type' => self::INT, 'env' => 'AUTH_REMEMBER_DAYS'],
            'auth.password_min'      => ['label' => 'Минимальная длина пароля', 'type' => self::INT, 'env' => 'AUTH_PASSWORD_MIN'],
            'auth.sessions_keep_days' => ['label' => 'Дней хранить завершённые сессии', 'type' => self::INT, 'env' => 'AUTH_SESSIONS_KEEP_DAYS'],
            'auth.link_lifetime'     => ['label' => 'Жизнь ссылки подтверждения/сброса, сек', 'type' => self::INT, 'env' => 'AUTH_LINK_LIFETIME'],
        ],
        'Почта' => [
            'mail.driver' => [
                'label'   => 'Чем отправлять',
                'type'    => self::SELECT,
                'env'     => 'MAIL_DRIVER',
                'options' => ['mail' => 'mail()', 'smtp' => 'SMTP', 'mailer' => 'почтовый сервис', 'log' => 'в файл (разработка)', 'null' => 'никуда (тесты)'],
            ],
            'mail.from_email' => ['label' => 'Адрес отправителя', 'type' => self::STRING, 'env' => 'MAIL_FROM'],
            'mail.from_name'  => ['label' => 'Имя отправителя', 'type' => self::STRING, 'env' => 'MAIL_FROM_NAME'],
            'mail.smtp.host'  => ['label' => 'SMTP: хост', 'type' => self::STRING, 'env' => 'SMTP_HOST'],
            'mail.smtp.port'  => ['label' => 'SMTP: порт', 'type' => self::INT, 'env' => 'SMTP_PORT'],
            'mail.smtp.username' => ['label' => 'SMTP: пользователь', 'type' => self::STRING, 'env' => 'SMTP_USER'],
            'mail.smtp.password' => ['label' => 'SMTP: пароль', 'type' => self::SECRET, 'env' => 'SMTP_PASSWORD'],
            'mail.smtp.encryption' => [
                'label'   => 'SMTP: шифрование',
                'type'    => self::SELECT,
                'env'     => 'SMTP_ENCRYPTION',
                'options' => ['' => 'нет', 'ssl' => 'ssl (465)', 'tls' => 'tls (587)'],
            ],
            'mail.smtp.timeout'       => ['label' => 'SMTP: таймаут, сек', 'type' => self::INT, 'env' => 'SMTP_TIMEOUT'],
            'mail.smtp.verify_peer'   => ['label' => 'SMTP: проверять сертификат', 'type' => self::BOOL, 'env' => 'SMTP_VERIFY_PEER'],
            'mail.smtp.helo'          => ['label' => 'SMTP: имя для HELO', 'type' => self::STRING, 'env' => 'SMTP_HELO'],
            'mail.smtp.session_limit' => ['label' => 'SMTP: писем на сессию', 'type' => self::INT, 'env' => 'SMTP_SESSION_LIMIT'],
            'mail.service.url'     => ['label' => 'Почтовый сервис: адрес', 'type' => self::STRING, 'env' => 'MAIL_SERVICE_URL'],
            'mail.service.key'     => ['label' => 'Почтовый сервис: ключ', 'type' => self::SECRET, 'env' => 'MAIL_SERVICE_KEY'],
            'mail.service.timeout' => ['label' => 'Почтовый сервис: таймаут, сек', 'type' => self::INT, 'env' => 'MAIL_SERVICE_TIMEOUT'],
        ],
        'HTTP и интеграции' => [
            'http.ca_bundle'   => ['label' => 'Путь к CA-бандлу', 'type' => self::STRING, 'env' => 'HTTP_CA_BUNDLE', 'hint' => 'нужен на Windows без своего хранилища сертификатов'],
            'http.timeout'     => ['label' => 'Таймаут, сек', 'type' => self::INT, 'env' => 'HTTP_TIMEOUT'],
            'http.verify_peer' => ['label' => 'Проверять сертификат', 'type' => self::BOOL, 'env' => 'HTTP_VERIFY_PEER', 'hint' => 'выключать не надо: ключи интеграций достанутся тому, кто встанет посередине'],
        ],
        'Вебхуки и уведомления' => [
            'webhooks.enabled'        => ['label' => 'Вебхуки включены', 'type' => self::BOOL, 'env' => 'WEBHOOKS_ENABLED'],
            'webhooks.timeout'        => ['label' => 'Таймаут посылки, сек', 'type' => self::INT, 'env' => 'WEBHOOKS_TIMEOUT'],
            'webhooks.attempts'       => ['label' => 'Попыток на посылку', 'type' => self::INT, 'env' => 'WEBHOOKS_ATTEMPTS'],
            'webhooks.disable_after'  => ['label' => 'Неудач подряд до отключения', 'type' => self::INT, 'env' => 'WEBHOOKS_DISABLE_AFTER', 'hint' => '0 — не отключать'],
            'webhooks.keep_days'      => ['label' => 'Дней хранить журнал доставок', 'type' => self::INT, 'env' => 'WEBHOOKS_KEEP_DAYS'],
            'webhooks.queue'          => ['label' => 'Очередь для посылок', 'type' => self::STRING, 'env' => 'WEBHOOKS_QUEUE'],
            'notifications.keep_days' => ['label' => 'Дней хранить прочитанные уведомления', 'type' => self::INT, 'env' => 'NOTIFICATIONS_KEEP_DAYS'],
        ],
        'Очередь' => [
            'queue.sleep'                => ['label' => 'Пауза без задач, сек', 'type' => self::INT, 'env' => 'QUEUE_SLEEP'],
            'queue.worker_lifetime'      => ['label' => 'Жизнь процесса воркера, сек', 'type' => self::INT, 'env' => 'QUEUE_WORKER_LIFETIME'],
            'queue.maintenance_interval' => ['label' => 'Интервал обслуживания, сек', 'type' => self::INT, 'env' => 'QUEUE_MAINTENANCE_INTERVAL'],
            'queue.stuck_minutes'        => ['label' => 'Минут до «зависшей» задачи', 'type' => self::INT, 'env' => 'QUEUE_STUCK_MINUTES'],
            'queue.keep_days'            => ['label' => 'Дней хранить завершённые задачи', 'type' => self::INT, 'env' => 'QUEUE_KEEP_DAYS'],
            'queue.process_on_hit'       => ['label' => 'Разбирать очередь на каждом хите', 'type' => self::BOOL, 'env' => 'QUEUE_PROCESS_ON_HIT', 'hint' => 'подстраховка, не замена воркеру'],
        ],
        'Кэш и файлы' => [
            'cache.driver' => [
                'label'   => 'Хранилище кэша',
                'type'    => self::SELECT,
                'env'     => 'CACHE_DRIVER',
                'options' => ['file' => 'файлы', 'database' => 'база', 'array' => 'память процесса (тесты)'],
            ],
            'files.max_size' => ['label' => 'Предел размера загрузки, байт', 'type' => self::INT, 'env' => 'FILES_MAX_SIZE'],
        ],
        'Журнал и аудит' => [
            'log.level' => [
                'label'   => 'Подробность логов',
                'type'    => self::SELECT,
                'env'     => 'LOG_LEVEL',
                'options' => ['debug' => 'debug', 'info' => 'info', 'warning' => 'warning', 'error' => 'error'],
            ],
            'log.keep_days'  => ['label' => 'Дней хранить файлы логов', 'type' => self::INT, 'env' => 'LOG_KEEP_DAYS'],
            'log.tail_lines' => ['label' => 'Строк лога на странице', 'type' => self::INT, 'env' => 'LOG_TAIL_LINES'],
            'audit.keep_days' => ['label' => 'Дней хранить журнал действий', 'type' => self::INT, 'env' => 'AUDIT_KEEP_DAYS'],
        ],
        'Списки, выгрузка и импорт' => [
            'export.delimiter' => ['label' => 'Разделитель CSV', 'type' => self::STRING, 'env' => 'EXPORT_DELIMITER'],
            'export.max_rows'  => ['label' => 'Строк на выгрузку', 'type' => self::INT, 'env' => 'EXPORT_MAX_ROWS', 'hint' => 'больше — только задачей'],
            'export.keep_days' => ['label' => 'Дней хранить файлы фоновых выгрузок', 'type' => self::INT, 'env' => 'EXPORT_KEEP_DAYS'],
            'import.max_rows'  => ['label' => 'Строк на загрузку из CSV', 'type' => self::INT, 'env' => 'IMPORT_MAX_ROWS'],
            'trash.keep_days'  => ['label' => 'Дней держать корзину', 'type' => self::INT, 'env' => 'TRASH_KEEP_DAYS', 'hint' => '0 — не добивать никогда'],
        ],
        'Резервные копии' => [
            'backup.keep'         => ['label' => 'Копий хранить', 'type' => self::INT, 'env' => 'BACKUP_KEEP'],
            'backup.schedule'     => ['label' => 'Время ежедневной копии', 'type' => self::STRING, 'env' => 'BACKUP_SCHEDULE', 'hint' => '«03:30», пусто — не делать'],
            'backup.ftp.host'     => ['label' => 'FTP: хост', 'type' => self::STRING, 'env' => 'FTP_HOST', 'hint' => 'пусто — копии на FTP не уезжают'],
            'backup.ftp.port'     => ['label' => 'FTP: порт', 'type' => self::INT, 'env' => 'FTP_PORT'],
            'backup.ftp.username' => ['label' => 'FTP: пользователь', 'type' => self::STRING, 'env' => 'FTP_USER'],
            'backup.ftp.password' => ['label' => 'FTP: пароль', 'type' => self::SECRET, 'env' => 'FTP_PASSWORD'],
            'backup.ftp.path'     => ['label' => 'FTP: каталог', 'type' => self::STRING, 'env' => 'FTP_PATH'],
            'backup.ftp.timeout'  => ['label' => 'FTP: таймаут, сек', 'type' => self::INT, 'env' => 'FTP_TIMEOUT'],
        ],
        'Безопасность' => [
            'security.blocklist'             => ['label' => 'Проверять закрытые адреса', 'type' => self::BOOL, 'env' => 'SECURITY_BLOCKLIST'],
            'security.autoblock_attempts'    => ['label' => 'Попыток до автоблокировки', 'type' => self::INT, 'env' => 'SECURITY_AUTOBLOCK_ATTEMPTS'],
            'security.autoblock_window'      => ['label' => 'Окно автоблокировки, мин', 'type' => self::INT, 'env' => 'SECURITY_AUTOBLOCK_WINDOW'],
            'security.autoblock_minutes'     => ['label' => 'Минут блокировки адреса', 'type' => self::INT, 'env' => 'SECURITY_AUTOBLOCK_MINUTES'],
            'security.keep_days'             => ['label' => 'Дней хранить журнал событий', 'type' => self::INT, 'env' => 'SECURITY_KEEP_DAYS'],
            'security.key_expiry_notice_days' => ['label' => 'За сколько дней предупредить об истечении ключа', 'type' => self::INT, 'env' => 'KEY_EXPIRY_NOTICE_DAYS'],
        ],
        'Показатели и мониторинг' => [
            'metrics.enabled'       => ['label' => 'Счётчики запросов включены', 'type' => self::BOOL, 'env' => 'METRICS_ENABLED'],
            'metrics.slow_ms'       => ['label' => 'Порог медленного ответа, мс', 'type' => self::INT, 'env' => 'METRICS_SLOW_MS'],
            'metrics.allow'         => ['label' => 'Кому открыт /metrics', 'type' => self::STRING, 'env' => 'METRICS_ALLOW', 'hint' => 'адреса через запятую, можно CIDR; пусто — закрыт всем'],
            'monitor.enabled'        => ['label' => 'Присмотр за порогами включён', 'type' => self::BOOL, 'env' => 'MONITOR_ENABLED'],
            'monitor.interval'       => ['label' => 'Интервал проверки, сек', 'type' => self::INT, 'env' => 'MONITOR_INTERVAL'],
            'monitor.repeat_minutes' => ['label' => 'Повтор уведомления не чаще, мин', 'type' => self::INT, 'env' => 'MONITOR_REPEAT_MINUTES'],
            'monitor.failed_jobs'    => ['label' => 'Порог упавших задач', 'type' => self::INT, 'env' => 'MONITOR_FAILED_JOBS'],
            'monitor.queue_backlog'  => ['label' => 'Порог очереди задач', 'type' => self::INT, 'env' => 'MONITOR_QUEUE_BACKLOG'],
            'monitor.errors'         => ['label' => 'Порог ошибок', 'type' => self::INT, 'env' => 'MONITOR_ERRORS'],
            'monitor.free_bytes'     => ['label' => 'Порог свободного места, байт', 'type' => self::INT, 'env' => 'MONITOR_FREE_BYTES'],
        ],
    ];

    /** @var array<string, array<string, array{label: string, type: string, options?: array<string, string>, hint?: string}>>|null */
    private static ?array $groups = null;

    private static bool $applied = false;

    /**
     * Добавить свою настройку. Зовётся из config/settings.php при старте.
     *
     * @param array<string, string> $options варианты для типа select
     */
    public static function register(
        string $key,
        string $label,
        string $type = self::STRING,
        string $group = 'Приложение',
        array $options = [],
        string $hint = ''
    ): void {
        self::boot();

        $item = ['label' => $label, 'type' => $type];

        if ($options !== []) {
            $item['options'] = $options;
        }

        if ($hint !== '') {
            $item['hint'] = $hint;
        }

        self::$groups[$group][$key] = $item;
    }

    /**
     * Все настройки по разделам — в этом порядке они показываются в панели.
     *
     * @return array<string, array<string, array{label: string, type: string, options?: array<string, string>, hint?: string}>>
     */
    public static function groups(): array
    {
        self::boot();

        return self::$groups;
    }

    /**
     * Описание одной настройки или null, если такой в реестре нет.
     *
     * @return array{label: string, type: string, options?: array<string, string>, hint?: string}|null
     */
    public static function describe(string $key): ?array
    {
        foreach (self::groups() as $items) {
            if (isset($items[$key])) {
                return $items[$key];
            }
        }

        return null;
    }

    public static function known(string $key): bool
    {
        return self::describe($key) !== null;
    }

    /**
     * Накладывает значения из базы на конфигурацию. Зовётся один раз за процесс.
     *
     * База может быть недоступна (установщик, упавший MySQL) — это не повод
     * не отвечать вовсе: работаем на том, что в `.env`.
     */
    public static function apply(): void
    {
        if (self::$applied) {
            return;
        }

        self::$applied = true;

        try {
            foreach (self::stored() as $key => $value) {
                if (self::known($key)) {
                    Config::set($key, self::cast($key, $value));
                }
            }
        } catch (Throwable $e) {
            (new Logger('app'))->warning('Настройки из базы не применились', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Значение настройки: из базы, а если там нет — из `.env`.
     */
    public static function value(string $key): mixed
    {
        $stored = self::stored();

        return array_key_exists($key, $stored)
            ? self::cast($key, $stored[$key])
            : Config::get($key);
    }

    /**
     * Задано ли значение в базе — в панели такие помечаются «изменено».
     */
    public static function overridden(string $key): bool
    {
        return array_key_exists($key, self::stored());
    }

    /**
     * Пишет значение в базу и сразу применяет его к текущему процессу.
     */
    public static function put(string $key, mixed $value): void
    {
        if (!self::known($key)) {
            throw new ProtonException('Неизвестная настройка: ' . $key);
        }

        $raw = self::describe($key)['type'] === self::SECRET
            ? Crypto::encrypt((string) $value)
            : self::stringify($key, $value);

        Setting::set(self::PREFIX . $key, $raw);

        self::$cache = null;

        Config::set($key, self::cast($key, $raw));
    }

    /**
     * Убирает значение из базы: настройка снова берётся из `.env`.
     */
    public static function forget(string $key): void
    {
        Setting::forget(self::PREFIX . $key);

        self::$cache = null;

        // Значение из .env читаем заново: в Config лежит то, что перекрыли
        Config::set($key, self::envValue($key));
    }

    /**
     * Проверяет присланное значение. Возвращает текст ошибки или пустую строку.
     */
    public static function problem(string $key, mixed $value): string
    {
        $item = self::describe($key);

        if ($item === null) {
            return 'Неизвестная настройка';
        }

        $text = trim((string) $value);

        return match ($item['type']) {
            self::INT    => preg_match('/^-?\d+$/', $text) === 1 ? '' : 'Нужно целое число',
            self::SELECT => array_key_exists($text, $item['options'] ?? []) ? '' : 'Значение не из списка',
            default      => '',
        };
    }

    /**
     * Собирает `.env` таким, каким он был бы, если бы значения из панели были
     * записаны прямо в файл. За основу берётся действующий `.env` (а если его
     * нет — `.env.example`): строки известных переменных заменяются текущим
     * эффективным значением (база, а где не перекрыто — то же, что уже в
     * файле), остальное остаётся как есть. Ключ приложения и настройки базы
     * в реестре не участвуют, поэтому файл всегда остаётся рабочим.
     *
     * Секреты выгружаются расшифрованными: `.env` и так лежит на сервере
     * открытым текстом, а иначе перенести настройку почтового сервиса или
     * FTP на другое окружение было бы нечем.
     */
    public static function exportEnv(): string
    {
        $map = [];

        foreach (self::groups() as $items) {
            foreach ($items as $key => $item) {
                if (isset($item['env']) && $item['env'] !== '') {
                    $map[$item['env']] = $key;
                }
            }
        }

        $base = APP_ROOT . '/.env';
        $file = is_file($base) ? $base : APP_ROOT . '/.env.example';
        $lines = is_file($file) ? file($file, FILE_IGNORE_NEW_LINES) : [];

        if ($lines === false) {
            $lines = [];
        }

        $seen = [];

        foreach ($lines as $i => $line) {
            $trimmed = ltrim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '#') || !str_contains($trimmed, '=')) {
                continue;
            }

            $name = trim(explode('=', $trimmed, 2)[0]);

            if (!isset($map[$name])) {
                continue;
            }

            $lines[$i] = $name . '=' . self::envValueText($map[$name]);
            $seen[$name] = true;
        }

        $missing = array_diff_key($map, $seen);

        if ($missing !== []) {
            $lines[] = '';
            $lines[] = '# --- Добавлено из панели настроек ---';

            foreach ($missing as $name => $key) {
                $lines[] = $name . '=' . self::envValueText($key);
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Текущее значение настройки в виде строки для `.env`.
     */
    private static function envValueText(string $key): string
    {
        $item = self::describe($key);
        $value = self::value($key);

        if (($item['type'] ?? self::STRING) === self::BOOL) {
            return $value ? 'true' : 'false';
        }

        $text = (string) $value;

        return str_contains($text, ' ') || str_contains($text, '#') ? '"' . $text . '"' : $text;
    }

    /**
     * Сбрасывает реестр и кэш значений — нужно тестам.
     */
    public static function reset(): void
    {
        self::$groups  = null;
        self::$cache   = null;
        self::$applied = false;
    }

    /** @var array<string, string>|null Значения из базы, прочитанные один раз */
    private static ?array $cache = null;

    /**
     * Значения из базы: ключ без префикса => строка как лежит.
     *
     * @return array<string, string>
     */
    private static function stored(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $values = [];

        foreach (Setting::all() as $key => $value) {
            if (str_starts_with($key, self::PREFIX)) {
                $values[substr($key, strlen(self::PREFIX))] = $value;
            }
        }

        return self::$cache = $values;
    }

    /**
     * Приводит строку из базы к типу настройки.
     */
    private static function cast(string $key, string $value): mixed
    {
        $item = self::describe($key);

        return match ($item['type'] ?? self::STRING) {
            self::INT    => (int) $value,
            self::BOOL   => $value === '1',
            self::SECRET => Crypto::decrypt($value),
            default      => $value,
        };
    }

    /**
     * Приводит значение из формы к строке для базы.
     */
    private static function stringify(string $key, mixed $value): string
    {
        $item = self::describe($key);

        if (($item['type'] ?? self::STRING) === self::BOOL) {
            return $value === true || $value === 1 || $value === '1' || $value === 'on' ? '1' : '0';
        }

        return (string) $value;
    }

    /**
     * Значение из `.env` в обход базы: нужно при сбросе настройки.
     */
    private static function envValue(string $key): mixed
    {
        // Конфиг уже прочитан и перекрыт, поэтому читаем файл заново
        $file = APP_ROOT . '/config/config.php';
        $data = is_file($file) ? (array) require $file : [];
        $value = $data;

        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return null;
            }

            $value = $value[$part];
        }

        return $value;
    }

    private static function boot(): void
    {
        if (self::$groups !== null) {
            return;
        }

        self::$groups = self::CORE;

        $file = APP_ROOT . '/config/settings.php';

        if (is_file($file)) {
            require $file;
        }
    }
}
