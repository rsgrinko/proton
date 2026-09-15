<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Support;

use Rsgrinko\Proton\Database\Query\Builder;

/**
 * Сводки для отчётов: сколько записей по дням и как они делятся по колонке.
 * Считает база — в память поднимается готовый ряд, а не таблица.
 *
 *     Reports::series(User::query(), 'created_at', '2026-09-01', '2026-09-15');
 *     // ['2026-09-01' => 3, '2026-09-02' => 0, …] — дни без записей тоже в ряду
 *
 * Дни без записей возвращаются нулями: иначе график врёт, а в выгрузке
 * пропадают пустые сутки.
 */
final class Reports
{
    public const DAY   = 'day';
    public const WEEK  = 'week';
    public const MONTH = 'month';

    /**
     * Ряд «период => сколько» по колонке времени.
     *
     * @return array<string, int>
     */
    public static function series(Builder $query, string $column, string $from, string $to, string $step = self::DAY): array
    {
        $expression = self::period($query, $column, $step);

        // Группируем по алиасу, а не по выражению: имена колонок построитель
        // проверяет, и strftime() он справедливо не пропустит
        $rows = self::rows(
            (clone $query)
                ->select($expression . ' AS period', 'COUNT(*) AS total')
                ->where($column, '>=', $from . ' 00:00:00')
                ->where($column, '<=', $to . ' 23:59:59')
                ->groupBy('period')
                ->orderBy('period')
        );

        $counted = [];

        foreach ($rows as $row) {
            $counted[(string) ($row['period'] ?? '')] = (int) ($row['total'] ?? 0);
        }

        $series = [];

        foreach (self::steps($from, $to, $step) as $key) {
            $series[$key] = $counted[$key] ?? 0;
        }

        return $series;
    }

    /**
     * Ряд «значение колонки => сколько», от большего к меньшему.
     *
     * @return array<string, int>
     */
    public static function breakdown(Builder $query, string $column, int $limit = 10): array
    {
        $rows = self::rows(
            (clone $query)
                ->select($column, 'COUNT(*) AS total')
                ->groupBy($column)
                ->orderBy('total', 'desc')
                ->limit($limit)
        );

        $out = [];

        foreach ($rows as $row) {
            $key = (string) ($row[$column] ?? '');

            $out[$key === '' ? '—' : $key] = (int) ($row['total'] ?? 0);
        }

        return $out;
    }

    /**
     * Строки как есть: у построителя моделей для этого свой метод, иначе
     * агрегат приехал бы моделью без нужных колонок.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function rows(Builder $query): array
    {
        return method_exists($query, 'rows') ? $query->rows() : $query->get();
    }

    /**
     * Сумма ряда — подпись под сводкой.
     *
     * @param array<string, int> $series
     */
    public static function total(array $series): int
    {
        return array_sum($series);
    }

    /**
     * Выражение группировки по периоду. Диалекты разные, ряд одинаковый.
     */
    private static function period(Builder $query, string $column, string $step): string
    {
        $sqlite = $query->connection()->isSqlite();

        return match ($step) {
            self::MONTH => $sqlite ? "strftime('%Y-%m', " . $column . ')' : "DATE_FORMAT(" . $column . ", '%Y-%m')",
            // Неделю обозначаем датой понедельника: номера недель у SQLite, MySQL
            // и PHP считаются по-разному и на стыке годов разъезжаются
            self::WEEK  => $sqlite
                ? "date(" . $column . ", 'weekday 0', '-6 days')"
                : "DATE(DATE_SUB(" . $column . ", INTERVAL WEEKDAY(" . $column . ") DAY))",
            default     => $sqlite ? "strftime('%Y-%m-%d', " . $column . ')' : "DATE_FORMAT(" . $column . ", '%Y-%m-%d')",
        };
    }

    /**
     * Все периоды между датами — чтобы в ряду не было дырок.
     *
     * @return array<int, string>
     */
    private static function steps(string $from, string $to, string $step): array
    {
        $start = strtotime($from);
        $end   = strtotime($to);

        if ($start === false || $end === false || $start > $end) {
            return [];
        }

        $keys    = [];
        $current = $start;

        while ($current <= $end) {
            $keys[] = match ($step) {
                self::MONTH => date('Y-m', $current),
                self::WEEK  => date('Y-m-d', (int) strtotime('monday this week', $current)),
                default     => date('Y-m-d', $current),
            };

            $current = strtotime(match ($step) {
                self::MONTH => '+1 month',
                self::WEEK  => '+1 week',
                default     => '+1 day',
            }, $current);
        }

        return array_values(array_unique($keys));
    }
}
