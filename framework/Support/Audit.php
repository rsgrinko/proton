<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Support;

use Rsgrinko\Proton\Auth\Auth;
use Rsgrinko\Proton\Models\AuditEntry;
use Throwable;

/**
 * Запись в журнал действий. Контроллер после успешного действия зовёт
 * Audit::created(), updated(), deleted() или action().
 *
 * Помощник статический и работе не мешает: упавшая запись уходит в лог,
 * а действие доводится до конца — код может приехать раньше миграции.
 *
 * Секреты сюда не попадают: пароли, ключи и токены заменяются отметкой
 * «изменено». Журнал читают все, у кого есть право audit.view.
 */
final class Audit
{
    /** @var array<int, string> Поля, значения которых в журнал не пишем */
    private const SECRETS = ['password', 'password_hash', 'token', 'token_hash', 'secret', 'api_key', 'key'];

    /**
     * @param array<string, array{0: mixed, 1: mixed}> $changes
     */
    public static function created(string $entity, int|string $id, string $description, array $changes = []): void
    {
        self::write(AuditEntry::CREATED, $entity, $id, $description, $changes);
    }

    /**
     * @param array<string, array{0: mixed, 1: mixed}> $changes
     */
    public static function updated(string $entity, int|string $id, string $description, array $changes = []): void
    {
        self::write(AuditEntry::UPDATED, $entity, $id, $description, $changes);
    }

    public static function deleted(string $entity, int|string $id, string $description): void
    {
        self::write(AuditEntry::DELETED, $entity, $id, $description);
    }

    /**
     * Действие, которое не создаёт и не удаляет запись: перезапуск воркера,
     * повтор задачи, выпуск ключа.
     *
     * @param array<string, array{0: mixed, 1: mixed}> $changes
     */
    public static function action(string $entity, int|string $id, string $description, array $changes = []): void
    {
        self::write(AuditEntry::ACTION, $entity, $id, $description, $changes);
    }

    public static function login(string $login, bool $success = true): void
    {
        self::write(
            $success ? AuditEntry::LOGIN : AuditEntry::ACTION,
            'user',
            $login,
            $success ? 'вход в систему' : 'неудачная попытка входа'
        );
    }

    public static function logout(string $login): void
    {
        self::write(AuditEntry::LOGOUT, 'user', $login, 'выход из системы');
    }

    /**
     * Что изменилось между двумя наборами значений: поле => [было, стало].
     * Считается вокруг сохранения — до и после.
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     *
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    public static function between(array $before, array $after): array
    {
        $changes = [];

        foreach ($after as $field => $value) {
            $old = $before[$field] ?? null;

            if ((string) $old === (string) $value) {
                continue;
            }

            $changes[$field] = self::secret($field)
                ? ['скрыто', 'изменено']
                : [self::short($old), self::short($value)];
        }

        return $changes;
    }

    /**
     * @param array<string, array{0: mixed, 1: mixed}> $changes
     */
    private static function write(string $action, string $entity, int|string $id, string $description, array $changes = []): void
    {
        try {
            $viewer = Auth::viewer();

            AuditEntry::write(
                $action,
                $entity,
                $id,
                $description,
                $changes,
                $viewer->id(),
                $viewer->login(),
                ClientIp::fromGlobals()
            );
        } catch (Throwable $e) {
            // Журнал не должен ронять действие, ради которого его пишут
            (new Logger('audit'))->error('Не удалось записать в журнал', [
                'action' => $action,
                'entity' => $entity,
                'error'  => $e->getMessage(),
            ]);
        }
    }

    private static function secret(string $field): bool
    {
        $field = strtolower($field);

        foreach (self::SECRETS as $needle) {
            if (str_contains($field, $needle)) {
                return true;
            }
        }

        return false;
    }

    private static function short(mixed $value): string
    {
        if (is_array($value)) {
            $value = implode(', ', array_map(static fn (mixed $item): string => (string) $item, $value));
        }

        if (is_bool($value)) {
            $value = $value ? 'да' : 'нет';
        }

        return Str::limit(trim((string) $value), 120);
    }
}
