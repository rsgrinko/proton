<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Support;

/**
 * Сбор запросов к базе для отладки: сколько, как долго и что повторяется.
 *
 * Включается только при APP_DEBUG: на бою каждый запрос не нужно ни считать,
 * ни держать в памяти. Медленные запросы пишутся в лог всегда — там они стоят
 * одной строки, зато находятся потом.
 *
 * Панель внизу страницы (`View::partial('profiler')`) показывает итог: сколько
 * запросов, сколько времени, что повторялось. Повторы важнее прочего: один и
 * тот же SELECT двадцать раз — это N+1, и его видно сразу.
 */
final class Profiler
{
    /** @var array<int, array{sql: string, ms: float, rows: int}> */
    private static array $queries = [];

    private static float $started = 0.0;

    private static bool $enabled = false;

    /**
     * Включён ли сбор. Считается один раз за процесс.
     */
    public static function enabled(): bool
    {
        static $decided = false;

        if (!$decided) {
            self::$enabled = (bool) Config::get('app.debug', false);
            self::$started = microtime(true);
            $decided       = true;
        }

        return self::$enabled;
    }

    /**
     * Записать выполненный запрос. Медленный уходит в лог независимо от отладки.
     */
    public static function query(string $sql, float $milliseconds, int $rows = 0): void
    {
        $slow = (float) Config::get('db.slow_ms', 200);

        if ($slow > 0 && $milliseconds >= $slow) {
            (new Logger('db'))->warning('Медленный запрос', [
                'ms'  => round($milliseconds, 1),
                'sql' => mb_substr(preg_replace('/\s+/u', ' ', $sql) ?? $sql, 0, 500),
            ]);
        }

        if (!self::enabled()) {
            return;
        }

        // Больше тысячи запросов на страницу — уже не отладка, а авария;
        // держать их все в памяти незачем
        if (count(self::$queries) >= 1000) {
            return;
        }

        self::$queries[] = [
            'sql'  => preg_replace('/\s+/u', ' ', trim($sql)) ?? $sql,
            'ms'   => round($milliseconds, 2),
            'rows' => $rows,
        ];
    }

    /**
     * Все собранные запросы.
     *
     * @return array<int, array{sql: string, ms: float, rows: int}>
     */
    public static function queries(): array
    {
        return self::$queries;
    }

    public static function count(): int
    {
        return count(self::$queries);
    }

    /**
     * Сколько всего времени ушло на базу, мс.
     */
    public static function time(): float
    {
        return round(array_sum(array_column(self::$queries, 'ms')), 1);
    }

    /**
     * Сколько времени прошло с начала запроса, мс.
     */
    public static function elapsed(): float
    {
        return self::$started > 0 ? round((microtime(true) - self::$started) * 1000, 1) : 0.0;
    }

    /**
     * Запросы, повторявшиеся больше раза: «запрос => сколько раз». Это и есть
     * N+1, если число большое.
     *
     * @return array<string, int>
     */
    public static function repeats(int $min = 2): array
    {
        $counts = [];

        foreach (self::$queries as $query) {
            $key          = $query['sql'];
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        $repeats = array_filter($counts, static fn (int $count): bool => $count >= max(2, $min));

        arsort($repeats);

        return $repeats;
    }

    /**
     * Самые долгие запросы.
     *
     * @return array<int, array{sql: string, ms: float, rows: int}>
     */
    public static function slowest(int $limit = 5): array
    {
        $queries = self::$queries;

        usort($queries, static fn (array $a, array $b): int => $b['ms'] <=> $a['ms']);

        return array_slice($queries, 0, max(1, $limit));
    }

    /**
     * Забыть собранное — нужно тестам и воркеру: он живёт часами.
     */
    public static function reset(): void
    {
        self::$queries = [];
        self::$started = microtime(true);
    }
}
