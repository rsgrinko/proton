<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Support;

use Rsgrinko\Proton\Database\Model\Model;
use Rsgrinko\Proton\Database\Model\Query;

/**
 * Корзина: что можно вернуть после удаления.
 *
 * Мягкое удаление у моделей было всегда (`$softDelete = true`), но добраться
 * до удалённого можно было только кодом. Реестр говорит, какие модели
 * показывать в разделе «Корзина» и как их подписывать.
 *
 *     Trash::register('notes', Note::class, 'Заметки', static fn (Note $n): string => (string) $n->title);
 *
 * Свои модели приложение дописывает в `config/trash.php`.
 */
final class Trash
{
    /** @var array<string, array{model: class-string<Model>, label: string, title: callable, permission: string, entity: string}>|null */
    private static ?array $kinds = null;

    /**
     * Показать модель в корзине.
     *
     * @param class-string<Model>     $model
     * @param callable(Model): string $title как назвать запись в списке
     */
    public static function register(
        string $key,
        string $model,
        string $label,
        callable $title,
        string $permission = '',
        string $entity = ''
    ): void {
        self::boot();

        self::$kinds[$key] = [
            'model'      => $model,
            'label'      => $label,
            'title'      => $title,
            'permission' => $permission,
            // Как раздел называется в журнале действий: там единственное число
            // (user, role), а ключ раздела — множественное
            'entity'     => $entity !== '' ? $entity : $key,
        ];
    }

    /**
     * Все разделы корзины.
     *
     * @return array<string, array{model: class-string<Model>, label: string, title: callable, permission: string}>
     */
    public static function kinds(): array
    {
        self::boot();

        return self::$kinds ?? [];
    }

    /**
     * Описание раздела или null, если такого нет.
     *
     * @return array{model: class-string<Model>, label: string, title: callable, permission: string}|null
     */
    public static function kind(string $key): ?array
    {
        return self::kinds()[$key] ?? null;
    }

    /**
     * Запрос к удалённым записям раздела.
     */
    public static function query(string $key): ?Query
    {
        $kind = self::kind($key);

        if ($kind === null) {
            return null;
        }

        /** @var class-string<Model> $model */
        $model = $kind['model'];

        return $model::query()->onlyTrashed();
    }

    /**
     * Сколько удалённого лежит в каждом разделе.
     *
     * @return array<string, int>
     */
    public static function counts(): array
    {
        $counts = [];

        foreach (array_keys(self::kinds()) as $key) {
            $counts[$key] = self::query($key)?->count() ?? 0;
        }

        return $counts;
    }

    /**
     * Добить всё, что лежит в корзине дольше стольких дней. Зовётся уборкой
     * воркера: корзина не архив, вечно держать удалённое незачем.
     */
    public static function purge(int $days): int
    {
        if ($days <= 0) {
            return 0;
        }

        $edge    = date('Y-m-d H:i:s', time() - $days * 86400);
        $removed = 0;

        foreach (array_keys(self::kinds()) as $key) {
            $query = self::query($key);

            if ($query === null) {
                continue;
            }

            $removed += $query->where(Model::DELETED_AT, '<', $edge)->forceDelete();
        }

        return $removed;
    }

    /**
     * Забыть реестр — нужно тестам.
     */
    public static function reset(): void
    {
        self::$kinds = null;
    }

    /**
     * Реестр ядра плюс config/trash.php приложения.
     */
    private static function boot(): void
    {
        if (self::$kinds !== null) {
            return;
        }

        self::$kinds = [];

        $file = APP_ROOT . '/config/trash.php';

        if (is_file($file)) {
            require $file;
        }
    }
}
