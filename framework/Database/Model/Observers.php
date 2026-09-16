<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Database\Model;

/**
 * Наблюдатели моделей: методы жизненного цикла одной модели в одном классе,
 * а не instanceof-проверки в общем слушателе шины событий.
 *
 *     final class NoteObserver
 *     {
 *         public function deleted(Model $model, bool $force = false): void
 *         {
 *             if ($force && $model instanceof Note) {
 *                 Attachment::detachAll('note', $model->id());
 *             }
 *         }
 *     }
 *
 *     Observers::register(Note::class, NoteObserver::class);   // config/observers.php
 *
 * Вызывается метод с тем же именем, что у события модели (docs/DATABASE.md,
 * «События модели»): saving, creating, updating, created, updated, saved,
 * deleting, deleted, restoring, restored. Метода нет — событие просто не
 * обрабатывается, пустые заглушки писать не нужно. «До»-методы отменяют
 * операцию тем же способом, что и слушатель на шине: вернуть false.
 *
 * Наблюдатель не заменяет `Events::listen('model.*', ...)` — тот продолжает
 * работать рядом. Разница только в том, где жить логике: одна проверка вида
 * `if ($payload['model'] instanceof Note)` в общем файле — это нормально,
 * несколько таких проверок на разные модели в одном слушателе — уже нет.
 */
final class Observers
{
    /** @var array<class-string<Model>, class-string>|null */
    private static ?array $map = null;

    /** @var array<class-string<Model>, object> */
    private static array $instances = [];

    /**
     * Наблюдатель класса модели. Зовётся из config/observers.php.
     *
     * @param class-string<Model> $model
     * @param class-string        $observer
     */
    public static function register(string $model, string $observer): void
    {
        self::boot();

        self::$map[$model] = $observer;
    }

    /**
     * Наблюдатель модели, если он есть — точное совпадение класса, без
     * поиска по родителям: модели, у которых он нужен, обычно final.
     *
     * @param class-string<Model> $model
     */
    public static function for(string $model): ?object
    {
        self::boot();

        if (!isset(self::$map[$model])) {
            return null;
        }

        return self::$instances[$model] ??= new (self::$map[$model])();
    }

    /**
     * Забыть наблюдателей — нужно тестам, которые заводят своих.
     */
    public static function reset(): void
    {
        self::$map       = null;
        self::$instances = [];
    }

    private static function boot(): void
    {
        if (self::$map !== null) {
            return;
        }

        self::$map = [];

        $file = APP_ROOT . '/config/observers.php';

        if (is_file($file)) {
            require $file;
        }
    }
}
