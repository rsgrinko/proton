<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Support;

use Rsgrinko\Proton\Access\Viewer;
use Rsgrinko\Proton\Database\Query\Builder;

/**
 * Реестр выгрузок: что и как выгружать в фоне.
 *
 * Синхронная выгрузка (`Controller::exportCsv`) собирает файл в памяти и потому
 * ограничена `EXPORT_MAX_ROWS`. Большую выборку делает задача очереди, а ей
 * нельзя передать готовый запрос — только его имя и параметры. Отсюда реестр:
 *
 *     Exports::register('users', 'Пользователи',
 *         static fn (array $params, Viewer $viewer): Builder => User::query(),
 *         ['login' => 'Логин', 'email' => 'Почта'],
 *         Permission::USERS_MANAGE
 *     );
 *
 * Свои выгрузки приложение дописывает в `config/exports.php`.
 */
final class Exports
{
    /** @var array<string, array{label: string, query: callable, columns: array<string, mixed>, permission: string}>|null */
    private static ?array $kinds = null;

    /**
     * @param callable(array<string, string>, Viewer): Builder $query
     * @param array<string, string|array{0: string, 1: callable}> $columns
     */
    public static function register(string $key, string $label, callable $query, array $columns, string $permission = ''): void
    {
        self::boot();

        self::$kinds[$key] = [
            'label'      => $label,
            'query'      => $query,
            'columns'    => $columns,
            'permission' => $permission,
        ];
    }

    /**
     * @return array<string, array{label: string, query: callable, columns: array<string, mixed>, permission: string}>
     */
    public static function all(): array
    {
        self::boot();

        return self::$kinds ?? [];
    }

    /**
     * @return array{label: string, query: callable, columns: array<string, mixed>, permission: string}|null
     */
    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /**
     * Запрос выгрузки с теми же параметрами, что были на экране.
     *
     * @param array<string, string> $params
     */
    public static function query(string $key, array $params, Viewer $viewer): ?Builder
    {
        $kind = self::get($key);

        return $kind === null ? null : ($kind['query'])($params, $viewer);
    }

    /**
     * Забыть реестр — нужно тестам.
     */
    public static function reset(): void
    {
        self::$kinds = null;
    }

    private static function boot(): void
    {
        if (self::$kinds !== null) {
            return;
        }

        self::$kinds = [];

        $file = APP_ROOT . '/config/exports.php';

        if (is_file($file)) {
            require $file;
        }
    }
}
