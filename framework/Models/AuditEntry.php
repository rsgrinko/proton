<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Models;

use Rsgrinko\Proton\Database\Connection;
use Rsgrinko\Proton\Database\Model\Model;

/**
 * Журнал действий: кто, что сделал, над какой записью и что именно изменилось.
 *
 * Логин пишем строкой рядом с номером: пользователя могут удалить, а журнал
 * должен остаться читаемым. Секреты сюда не попадают ни в каком виде —
 * пароли и ключи заменяются отметкой «изменено».
 */
final class AuditEntry extends Model
{
    protected static string $table = 'audit_log';

    protected bool $timestamps = false;

    protected array $casts = ['changes' => 'json'];

    public const CREATED = 'created';
    public const UPDATED = 'updated';
    public const DELETED = 'deleted';
    public const ACTION  = 'action';
    public const LOGIN   = 'login';
    public const LOGOUT  = 'logout';

    /**
     * Подписи действий для страницы журнала.
     *
     * @var array<string, string>
     */
    public const LABELS = [
        self::CREATED => 'создание',
        self::UPDATED => 'изменение',
        self::DELETED => 'удаление',
        self::ACTION  => 'действие',
        self::LOGIN   => 'вход',
        self::LOGOUT  => 'выход',
    ];

    /**
     * @param array<string, array{0: mixed, 1: mixed}> $changes поле => [было, стало]
     */
    public static function write(
        string $action,
        string $entity,
        int|string $entityId,
        string $description,
        array $changes = [],
        int $userId = 0,
        string $login = '',
        string $ip = ''
    ): void {
        $entry = new self();

        $entry->forceFill([
            'user_id'     => $userId,
            'user_login'  => $login,
            'action'      => $action,
            'entity'      => $entity,
            'entity_id'   => (string) $entityId,
            'description' => mb_substr($description, 0, 500),
            'changes'     => $changes === [] ? null : json_encode($changes, JSON_UNESCAPED_UNICODE),
            'ip'          => $ip,
            'created_at'  => Connection::now(),
        ])->save();
    }

    public static function label(string $action): string
    {
        return self::LABELS[$action] ?? $action;
    }

    /**
     * Чистка по сроку — зовётся воркером.
     */
    public static function purge(int $days): int
    {
        if ($days <= 0) {
            return 0;
        }

        return self::query()
            ->where('created_at', '<', date('Y-m-d H:i:s', time() - $days * 86400))
            ->forceDelete();
    }
}
