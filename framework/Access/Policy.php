<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Access;

use Rsgrinko\Proton\Auth\Auth;
use Rsgrinko\Proton\Database\Model\Model;

/**
 * Правила на конкретную запись: можно ли этому человеку править вот эту заметку.
 *
 *     Policy::define('note.edit', static fn (Viewer $viewer, Note $note): bool =>
 *         (int) $note->raw('user_id') === $viewer->id());
 *
 *     Policy::allows('note.edit', $note);       // в коде
 *     $this->authorize('note.edit', $note);     // в контроллере, 403 при отказе
 *     View::can('note.edit', $note)             // в шаблоне
 *
 * Право (`Permission`) отвечает на вопрос «пускать ли в раздел», политика — на
 * вопрос «можно ли трогать именно эту запись». Права проверяет прослойка у
 * группы маршрутов, политику — контроллер там, где у него уже есть запись.
 *
 * Правила живут в коде (`config/policies.php`), в базе их нет: иначе после
 * выкладки нового раздела правила пришлось бы досыпать вручную.
 */
final class Policy
{
    /** @var array<string, callable(Viewer, mixed): bool>|null */
    private static ?array $rules = null;

    /**
     * Правило: кому и что можно с записью.
     *
     * @param callable(Viewer, mixed): bool $rule
     */
    public static function define(string $ability, callable $rule): void
    {
        self::boot();

        self::$rules[$ability] = $rule;
    }

    /**
     * Есть ли правило — нужно тем, кто хочет отличить «запрещено» от «правила нет».
     */
    public static function has(string $ability): bool
    {
        self::boot();

        return isset(self::$rules[$ability]);
    }

    /**
     * Можно ли. Правила нет — решает право с тем же именем: так политику можно
     * добавить позже, не переписывая места вызова.
     */
    public static function allows(string $ability, mixed $subject = null, ?Viewer $viewer = null): bool
    {
        self::boot();

        $viewer ??= Auth::viewer();

        if (!isset(self::$rules[$ability])) {
            return $viewer->can($ability);
        }

        // Полный доступ (право data.all) снимает вопрос о чужих записях — но
        // только если правило само не решило иначе для своей записи
        return (bool) (self::$rules[$ability])($viewer, $subject);
    }

    /**
     * Обратное — читается лучше, чем отрицание в условии.
     */
    public static function denies(string $ability, mixed $subject = null, ?Viewer $viewer = null): bool
    {
        return !self::allows($ability, $subject, $viewer);
    }

    /**
     * Хозяин записи: одинаковая проверка почти для всех правил приложения.
     */
    public static function owns(Viewer $viewer, mixed $subject, string $column = 'user_id'): bool
    {
        if (!$subject instanceof Model) {
            return false;
        }

        return (int) $subject->raw($column) === $viewer->id();
    }

    /**
     * Забыть правила — нужно тестам.
     */
    public static function reset(): void
    {
        self::$rules = null;
    }

    /**
     * Правила приложения. Файла нет — работают одни права.
     */
    private static function boot(): void
    {
        if (self::$rules !== null) {
            return;
        }

        self::$rules = [];

        $file = APP_ROOT . '/config/policies.php';

        if (is_file($file)) {
            require $file;
        }
    }
}
