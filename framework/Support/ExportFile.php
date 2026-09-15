<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Support;

use Rsgrinko\Proton\Database\Model\Model;
use Rsgrinko\Proton\Database\Query\Builder;

/**
 * Готовые файлы выгрузок: сборка строк, хранение в var/exports и уборка.
 *
 * Файлы лежат вне public и отдаются только по подписанной ссылке: в выгрузке
 * данные, которые видны не всем.
 */
final class ExportFile
{
    /** Сколько строк забираем из базы за один запрос */
    private const CHUNK = 500;

    /**
     * Заголовки таблицы по описанию колонок.
     *
     * @param array<string, string|array{0: string, 1: callable}> $columns
     *
     * @return array<int, string>
     */
    public static function headers(array $columns): array
    {
        $headers = [];

        foreach ($columns as $column) {
            $headers[] = is_array($column) ? $column[0] : $column;
        }

        return $headers;
    }

    /**
     * Строки выгрузки.
     *
     * @param array<string, string|array{0: string, 1: callable}> $columns
     *
     * @return array<int, array<int, mixed>>
     */
    public static function rows(Builder $query, array $columns): array
    {
        $rows = [];

        $query->chunk(self::CHUNK, static function (array $records) use ($columns, &$rows): void {
            foreach ($records as $record) {
                $line = [];

                foreach ($columns as $key => $column) {
                    $line[] = is_array($column) ? ($column[1])($record) : self::value($record, $key);
                }

                $rows[] = $line;
            }
        });

        return $rows;
    }

    /**
     * Сохранить готовый файл, вернуть его имя.
     */
    public static function save(string $kind, string $content): string
    {
        $dir = self::directory();

        @mkdir($dir, 0775, true);

        $name = Export::fileName($kind);

        file_put_contents($dir . '/' . $name, $content);

        return $name;
    }

    /**
     * Путь к файлу выгрузки. Имя проверяется: из адреса сюда приходит что угодно.
     */
    public static function path(string $name): ?string
    {
        if (preg_match('/^[A-Za-z0-9_\-]+-\d{4}-\d{2}-\d{2}-\d{6}\.csv$/', $name) !== 1) {
            return null;
        }

        $path = self::directory() . '/' . $name;

        return is_file($path) ? $path : null;
    }

    /**
     * Сколько строк в готовом файле (без заголовка) — для сообщения человеку.
     */
    public static function rowCount(string $content): int
    {
        return max(0, substr_count($content, "\n") - 1);
    }

    /**
     * Убрать файлы старше стольких дней: выгрузка нужна один раз, а данные
     * в ней лежать вечно не должны.
     */
    public static function purge(int $days): int
    {
        if ($days <= 0) {
            return 0;
        }

        $edge    = time() - $days * 86400;
        $removed = 0;

        foreach ((array) glob(self::directory() . '/*.csv') as $file) {
            $file = (string) $file;

            if (is_file($file) && filemtime($file) < $edge && @unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Где лежат готовые файлы.
     */
    public static function directory(): string
    {
        return (string) Config::get('export.path', APP_ROOT . '/var/exports');
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
