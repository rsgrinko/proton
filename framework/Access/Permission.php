<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Access;

/**
 * Реестр прав. Права живут в коде, а не в базе: право появляется вместе с кодом,
 * который его проверяет, и заводить под каждое миграцию незачем. В базе хранятся
 * только роли — наборы прав под именем.
 *
 * Право отвечает на вопрос «пускать ли в раздел», а не «чья это запись» — за второе
 * отвечает Scope. Исключение одно: data.all, оно как раз про чужие записи.
 *
 * Свои права приложение добавляет в config/permissions.php:
 *
 *     Permission::register('notes.view', 'Смотреть заметки', 'Заметки');
 */
final class Permission
{
    public const USERS_MANAGE  = 'users.manage';
    public const ROLES_MANAGE  = 'roles.manage';
    public const AUDIT_VIEW    = 'audit.view';
    public const LOGS_VIEW     = 'logs.view';
    public const SYSTEM_VIEW   = 'system.view';
    public const SYSTEM_MANAGE = 'system.manage';
    public const FILES_MANAGE  = 'files.manage';

    /** Видеть и править чужие записи, а не только свои */
    public const DATA_ALL = 'data.all';

    /**
     * Права ядра по разделам. Приложение дописывает свои через register().
     *
     * @var array<string, array<string, string>>
     */
    private const CORE = [
        'Сервис' => [
            self::USERS_MANAGE  => 'Управлять пользователями',
            self::ROLES_MANAGE  => 'Управлять ролями',
            self::AUDIT_VIEW    => 'Читать журнал действий',
            self::LOGS_VIEW     => 'Читать логи',
            self::SYSTEM_VIEW   => 'Смотреть состояние',
            self::SYSTEM_MANAGE => 'Управлять очередью и обслуживанием',
            self::FILES_MANAGE  => 'Управлять загруженными файлами',
            self::DATA_ALL      => 'Доступ к чужим данным, а не только к своим',
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
            self::USERS_MANAGE,
            self::ROLES_MANAGE,
            self::AUDIT_VIEW,
            self::LOGS_VIEW,
            self::SYSTEM_VIEW,
            self::SYSTEM_MANAGE,
            self::FILES_MANAGE,
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
