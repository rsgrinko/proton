<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Access;

use Rsgrinko\Proton\Database\Model\Query;
use Rsgrinko\Proton\Database\Query\Builder;

/**
 * Область видимости: чьи записи показывать. Либо все (администратор, консоль,
 * воркер), либо только записи одного владельца.
 *
 * Это отдельная от прав вещь: право пускает в раздел, область решает, какие
 * строки из него видно. Условие уезжает прямо в запрос — так фильтр нельзя
 * забыть в одном из десятка мест, где иначе проверяли бы вручную.
 *
 *     $notes = $scope->apply(Note::query())->orderBy('id', 'desc')->get();
 */
final class Scope
{
    /** null — ограничений нет */
    private ?int $ownerId;

    private function __construct(?int $ownerId)
    {
        $this->ownerId = $ownerId;
    }

    /**
     * Видно всё.
     */
    public static function all(): self
    {
        return new self(null);
    }

    /**
     * Видно только своё.
     */
    public static function owner(int $userId): self
    {
        return new self($userId);
    }

    public function isAll(): bool
    {
        return $this->ownerId === null;
    }

    /**
     * Владелец, от лица которого смотрим. 0 — ограничений нет.
     */
    public function ownerId(): int
    {
        return $this->ownerId ?? 0;
    }

    /**
     * Добавляет к запросу условие по владельцу.
     *
     * $sharedColumn — колонка «общая запись»: такие видно всем.
     */
    public function apply(Query $query, string $column = 'user_id', ?string $sharedColumn = null): Query
    {
        if ($this->isAll()) {
            return $query;
        }

        if ($sharedColumn === null) {
            return $query->where($column, $this->ownerId);
        }

        $owner  = $this->ownerId;
        $shared = $sharedColumn;

        // Замыкание получает базовый построитель: скобку собирает он, а не модель
        return $query->where(static function (Builder $nested) use ($column, $owner, $shared): void {
            $nested->where($column, $owner)->orWhere($shared, 1);
        });
    }

    /**
     * Своя ли запись — там, где строка уже прочитана. Правит и удаляет владелец,
     * даже если запись видно всем.
     *
     * @param array<string, mixed>|object $row
     */
    public function owns(array|object $row, string $column = 'user_id'): bool
    {
        if ($this->isAll()) {
            return true;
        }

        $value = is_array($row) ? ($row[$column] ?? 0) : ($row->{$column} ?? 0);

        return (int) $value === $this->ownerId;
    }
}
