<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Support;

use Rsgrinko\Proton\Database\Model\Model;
use Rsgrinko\Proton\Database\Query\Builder;
use Rsgrinko\Proton\Http\Response;

/**
 * Выгрузка списка файлом. Забирает тот же запрос, что показан на экране, —
 * вместе с фильтрами и сортировкой, иначе выгрузка расходится с тем, что человек
 * видел.
 *
 *     return Export::csv($filters->apply(User::query()), [
 *         'login' => 'Логин',
 *         'role'  => ['Роль', static fn (User $user): string => $roles[$user->raw('role_id')] ?? ''],
 *     ], 'users');
 *
 * Колонка — это «поле => подпись» или «поле => [подпись, как получить значение]».
 * Строки достаются порциями: миллион записей в память не поднимается.
 */
final class Export
{
    /** Сколько строк забираем за один запрос к базе */
    private const CHUNK = 500;

    /**
     * @param array<string, string|array{0: string, 1: callable}> $columns
     */
    public static function csv(Builder $query, array $columns, string $name): Response
    {
        $headers = [];

        foreach ($columns as $key => $column) {
            $headers[] = is_array($column) ? $column[0] : $column;
        }

        return Response::download(
            Csv::write(self::rows($query, $columns), $headers),
            self::fileName($name),
            'text/csv; charset=utf-8'
        );
    }

    /**
     * Сколько строк уедет в файл. Больше этого выгружать не даём: файл собирается
     * в памяти, а запрос идёт синхронно — на такие объёмы нужен не экспорт
     * из панели, а задача.
     */
    public static function limit(): int
    {
        return max(1, (int) Config::get('export.max_rows', 20000));
    }

    /**
     * Влезет ли выборка в выгрузку.
     */
    public static function fits(Builder $query): bool
    {
        return (clone $query)->count() <= self::limit();
    }

    /**
     * Имя файла: раздел и дата, чтобы в загрузках не копились export(3).csv.
     */
    public static function fileName(string $name, string $extension = 'csv'): string
    {
        return $name . '-' . date('Y-m-d-His') . '.' . $extension;
    }

    /**
     * Строки выгрузки по описанию колонок.
     *
     * @param array<string, string|array{0: string, 1: callable}> $columns
     *
     * @return iterable<int, array<int, mixed>>
     */
    private static function rows(Builder $query, array $columns): iterable
    {
        $out = [];

        $query->chunk(self::CHUNK, static function (array $rows) use ($columns, &$out): void {
            foreach ($rows as $row) {
                $line = [];

                foreach ($columns as $key => $column) {
                    $line[] = is_array($column) ? ($column[1])($row) : self::value($row, $key);
                }

                $out[] = $line;
            }
        });

        return $out;
    }

    /**
     * Значение поля и у модели, и у обычной строки запроса.
     */
    private static function value(mixed $row, string $key): mixed
    {
        if ($row instanceof Model) {
            return $row->raw($key);
        }

        if (is_array($row)) {
            return $row[$key] ?? '';
        }

        return $row->{$key} ?? '';
    }
}
