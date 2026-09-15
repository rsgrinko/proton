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
     * @var array<string, array<string, array{label: string, type: string, options?: array<string, string>, hint?: string}>>
     */
    private const CORE = [
        'Приложение' => [
            'app.name'     => ['label' => 'Название', 'type' => self::STRING],
            'ui.per_page'  => ['label' => 'Записей на странице', 'type' => self::INT, 'hint' => 'от 5 до 200'],
            'export.max_rows' => ['label' => 'Строк на выгрузку', 'type' => self::INT, 'hint' => 'больше — только задачей'],
            'log.level'    => [
                'label'   => 'Подробность логов',
                'type'    => self::SELECT,
                'options' => ['debug' => 'debug', 'info' => 'info', 'warning' => 'warning', 'error' => 'error'],
            ],
        ],
        'Вход и регистрация' => [
            'auth.registration'  => ['label' => 'Открыта регистрация', 'type' => self::BOOL],
            'auth.verify_email'  => ['label' => 'Требовать подтверждение почты', 'type' => self::BOOL],
            'auth.max_attempts'  => ['label' => 'Попыток входа до блокировки', 'type' => self::INT],
        ],
        'Почта' => [
            'mail.driver' => [
                'label'   => 'Чем отправлять',
                'type'    => self::SELECT,
                'options' => ['mail' => 'mail()', 'smtp' => 'SMTP', 'mailer' => 'почтовый сервис', 'log' => 'в файл (разработка)', 'null' => 'никуда (тесты)'],
            ],
            'mail.from_email' => ['label' => 'Адрес отправителя', 'type' => self::STRING],
            'mail.from_name'  => ['label' => 'Имя отправителя', 'type' => self::STRING],
        ],
        'Вебхуки и уведомления' => [
            'webhooks.enabled'          => ['label' => 'Вебхуки включены', 'type' => self::BOOL],
            'webhooks.attempts'         => ['label' => 'Попыток на посылку', 'type' => self::INT],
            'webhooks.disable_after'    => ['label' => 'Неудач подряд до отключения', 'type' => self::INT, 'hint' => '0 — не отключать'],
            'notifications.keep_days'   => ['label' => 'Дней хранить прочитанные уведомления', 'type' => self::INT],
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
