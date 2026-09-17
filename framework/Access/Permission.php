<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Access;

/**
 * Реестр прав. Право живёт в коде, а не в базе: право появляется вместе с кодом,
 * который его проверяет, и заводить под каждое миграцию незачем. В базе хранятся
 * только роли — наборы прав под именем.
 *
 * Право отвечает на вопрос «пускать ли в раздел», а не «чья это запись» — за второе
 * отвечает Scope. Исключение одно: data.all, оно как раз про чужие записи.
 *
 * У каждого раздела панели — своя пара «смотреть / менять» (где у раздела есть,
 * что менять), а не одно право на всё: кому-то нужно только видеть чужие ключи
 * API или подозрительную активность, не имея прав их трогать. Право «менять»
 * не включает в себя «смотреть» само по себе — маршруты и меню проверяют
 * `can:раздел.view|раздел.manage`, чтобы тот, кто умеет менять, не остался без
 * доступа к самому списку.
 *
 * Свои права приложение добавляет в config/permissions.php:
 *
 *     Permission::register('notes.view', 'Смотреть заметки', 'Заметки');
 */
final class Permission
{
    public const USERS_VIEW        = 'users.view';
    public const USERS_MANAGE      = 'users.manage';
    public const USERS_IMPERSONATE = 'users.impersonate';
    public const ROLES_VIEW        = 'roles.view';
    public const ROLES_MANAGE      = 'roles.manage';
    public const TOKENS_VIEW       = 'tokens.view';
    public const TOKENS_MANAGE     = 'tokens.manage';
    public const AUDIT_VIEW        = 'audit.view';
    public const LOGS_VIEW         = 'logs.view';
    public const SYSTEM_VIEW       = 'system.view';
    public const SYSTEM_MANAGE     = 'system.manage';
    public const FILES_MANAGE      = 'files.manage';
    public const WEBHOOKS_VIEW     = 'webhooks.view';
    public const WEBHOOKS_MANAGE   = 'webhooks.manage';
    public const WEBHOOKS_DELETE   = 'webhooks.delete';
    public const SETTINGS_VIEW     = 'settings.view';
    public const SETTINGS_MANAGE   = 'settings.manage';
    public const BACKUPS_VIEW      = 'backups.view';
    public const BACKUPS_MANAGE    = 'backups.manage';
    public const SECURITY_VIEW     = 'security.view';
    public const SECURITY_MANAGE   = 'security.manage';
    public const QUEUE_VIEW        = 'queue.view';
    public const QUEUE_MANAGE      = 'queue.manage';
    public const TRASH_VIEW        = 'trash.view';
    public const TRASH_MANAGE      = 'trash.manage';

    /** Видеть и править чужие записи, а не только свои */
    public const DATA_ALL = 'data.all';

    /**
     * Права ядра по разделам. Приложение дописывает свои через register().
     *
     * @var array<string, array<string, string>>
     */
    private const CORE = [
        'Пользователи' => [
            self::USERS_VIEW        => 'Просмотр пользователей',
            self::USERS_MANAGE      => 'Управление пользователями',
            self::USERS_IMPERSONATE => 'Вход под пользователем',
        ],
        'Роли' => [
            self::ROLES_VIEW   => 'Просмотр ролей',
            self::ROLES_MANAGE => 'Управление ролями',
        ],
        'Ключи API' => [
            self::TOKENS_VIEW   => 'Просмотр ключей API',
            self::TOKENS_MANAGE => 'Управление ключами API',
        ],
        'Вебхуки' => [
            self::WEBHOOKS_VIEW   => 'Просмотр вебхуков',
            self::WEBHOOKS_MANAGE => 'Управление вебхуками',
            self::WEBHOOKS_DELETE => 'Удаление вебхуков',
        ],
        'Система' => [
            self::SYSTEM_VIEW   => 'Просмотр состояния',
            self::SYSTEM_MANAGE => 'Управление обслуживанием',
            self::FILES_MANAGE  => 'Управление загруженными файлами',
        ],
        'Копии базы' => [
            self::BACKUPS_VIEW   => 'Просмотр копий базы',
            self::BACKUPS_MANAGE => 'Управление копиями базы',
        ],
        'Очередь задач' => [
            self::QUEUE_VIEW   => 'Просмотр очереди задач',
            self::QUEUE_MANAGE => 'Управление очередью задач',
        ],
        'Настройки' => [
            self::SETTINGS_VIEW   => 'Просмотр настроек',
            self::SETTINGS_MANAGE => 'Управление настройками',
        ],
        'Журналы' => [
            self::AUDIT_VIEW => 'Просмотр журнала действий',
            self::LOGS_VIEW  => 'Просмотр логов',
        ],
        'Безопасность' => [
            self::SECURITY_VIEW   => 'Просмотр подозрительной активности',
            self::SECURITY_MANAGE => 'Управление подозрительной активностью',
        ],
        'Корзина' => [
            self::TRASH_VIEW   => 'Просмотр корзины',
            self::TRASH_MANAGE => 'Управление корзиной',
        ],
        'Доступ к данным' => [
            self::DATA_ALL => 'Доступ к чужим данным',
        ],
    ];

    /** @var array<string, array<string, string>>|null Собранный реестр: раздел => [код => подпись] */
    private static ?array $groups = null;

    /**
     * Добавить своё право. Зовётся из config/permissions.php при старте.
     */
    public static function register(string $code, string $label, string $group = 'Приложение'): void
    {
        self::boot();

        self::$groups[$group][$code] = $label;
    }

    /**
     * Все права по разделам — в этом порядке они показываются в форме роли.
     *
     * @return array<string, array<string, string>>
     */
    public static function groups(): array
    {
        self::boot();

        return self::$groups;
    }

    /**
     * Все коды одним списком.
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        $codes = [];

        foreach (self::groups() as $group) {
            foreach (array_keys($group) as $code) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    public static function known(string $code): bool
    {
        return in_array($code, self::all(), true);
    }

    /**
     * Подпись права. Неизвестное показываем как есть — так видно, что в базе
     * осталось право от снятого раздела.
     */
    public static function label(string $code): string
    {
        foreach (self::groups() as $group) {
            if (isset($group[$code])) {
                return $group[$code];
            }
        }

        return $code;
    }

    /**
     * Оставляет только известные права, без повторов и в порядке реестра.
     *
     * @param array<int, mixed> $codes
     *
     * @return array<int, string>
     */
    public static function filter(array $codes): array
    {
        $codes = array_map(static fn (mixed $code): string => (string) $code, $codes);

        return array_values(array_filter(self::all(), static fn (string $code): bool => in_array($code, $codes, true)));
    }

    /**
     * Набор администратора: всё, что есть. Берётся из кода, а не из базы, —
     * список прав растёт вместе с разделами, и записанный когда-то набор
     * оставил бы администратора без нового раздела.
     *
     * @return array<int, string>
     */
    public static function admin(): array
    {
        return self::all();
    }

    /**
     * Набор обычного пользователя: всё, кроме управления сервисом и чужих данных.
     *
     * @return array<int, string>
     */
    public static function user(): array
    {
        $service = [
            self::USERS_VIEW,
            self::USERS_MANAGE,
            self::USERS_IMPERSONATE,
            self::ROLES_VIEW,
            self::ROLES_MANAGE,
            self::TOKENS_VIEW,
            self::TOKENS_MANAGE,
            self::AUDIT_VIEW,
            self::LOGS_VIEW,
            self::SYSTEM_VIEW,
            self::SYSTEM_MANAGE,
            self::FILES_MANAGE,
            self::WEBHOOKS_VIEW,
            self::WEBHOOKS_MANAGE,
            self::WEBHOOKS_DELETE,
            self::SETTINGS_VIEW,
            self::SETTINGS_MANAGE,
            self::BACKUPS_VIEW,
            self::BACKUPS_MANAGE,
            self::SECURITY_VIEW,
            self::SECURITY_MANAGE,
            self::QUEUE_VIEW,
            self::QUEUE_MANAGE,
            self::TRASH_VIEW,
            self::TRASH_MANAGE,
            self::DATA_ALL,
        ];

        return array_values(array_filter(
            self::all(),
            static fn (string $code): bool => !in_array($code, $service, true)
        ));
    }

    /**
     * Сбрасывает реестр — нужно тестам, которые регистрируют свои права.
     */
    public static function reset(): void
    {
        self::$groups = null;
    }

    private static function boot(): void
    {
        if (self::$groups !== null) {
            return;
        }

        self::$groups = self::CORE;

        $file = APP_ROOT . '/config/permissions.php';

        if (is_file($file)) {
            require $file;
        }
    }
}
