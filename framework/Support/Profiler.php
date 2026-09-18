<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Support;

/**
 * Сбор данных о запросе для отладки: сколько было SQL-запросов, кэша, событий
 * и задач очереди, как долго и что повторяется.
 *
 * Включается только при APP_DEBUG: на бою ничего из этого не считается и не
 * держится в памяти. Медленные SQL-запросы пишутся в лог всегда — там они
 * стоят одной строки, зато находятся потом.
 *
 * Панель внизу страницы показывает итог по вкладкам: «Выполнение» — прослойки
 * и контроллер по порядку с отметками времени, SQL с таймлайном, кэш
 * (hit/miss). Повторяющиеся SQL-запросы важнее прочего: один и тот же SELECT
 * двадцать раз — это N+1, и его видно сразу, а стек вызова у каждого запроса
 * показывает, из какого места кода он прилетел. Вкладка «Выполнение» — тот же
 * материал, что нужен для поиска, на что уходит время страницы: с ним переход
 * к оптимизации не начинается с гадания.
 *
 * Сама панель не рисуется в шаблоне напрямую: вместо неё layout оставляет
 * PLACEHOLDER, а `Kernel::handle()` подставляет вместо него готовый
 * `View::partial('profiler')` в самом конце — когда ответ уже прошёл через
 * все прослойки обратно и у каждой известна отметка выхода. Раньше панель
 * рисовалась изнутри контроллера (при рендере вида), и половина отметок
 * жизненного цикла просто не успевала случиться к этому моменту.
 */
final class Profiler
{
    /** Место панели в HTML — Kernel меняет его на готовую разметку в конце ответа */
    public const PLACEHOLDER = '<!--proton:profiler-->';

    /** @var array<int, array{sql: string, ms: float, rows: int, offset: float, type: string, trace: array<int, array{file: string, line: int, call: string}>}> */
    private static array $queries = [];

    /** @var array<int, array{key: string, hit: bool}> */
    private static array $cache = [];

    /** @var array<int, array{label: string, location: string, offset: float}> Контрольные точки жизненного цикла запроса */
    private static array $marks = [];

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
     * Порог медленного запроса из настроек — им же раскрашивается таймлайн в панели.
     */
    public static function slowThreshold(): float
    {
        return (float) Config::get('db.slow_ms', 200);
    }

    /**
     * Записать выполненный запрос. Медленный уходит в лог независимо от отладки.
     */
    public static function query(string $sql, float $milliseconds, int $rows = 0): void
    {
        $slow = self::slowThreshold();

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

        $normalized = preg_replace('/\s+/u', ' ', trim($sql)) ?? $sql;

        // Момент начала запроса относительно старта страницы — тем и рисуется
        // таймлайн в панели, «сейчас минус потраченное время»
        $offset = self::$started > 0
            ? round(((microtime(true) - $milliseconds / 1000) - self::$started) * 1000, 2)
            : 0.0;

        self::$queries[] = [
            'sql'    => $normalized,
            'ms'     => round($milliseconds, 2),
            'rows'   => $rows,
            'offset' => max(0.0, $offset),
            'type'   => self::queryType($normalized),
            'trace'  => self::trace(),
        ];
    }

    /**
     * Путь без APP_ROOT — панели он нужен коротким, полный ведёт только к тому же файлу.
     * APP_ROOT всегда с прямыми слэшами, а __FILE__ и debug_backtrace() на Windows
     * отдают обратные — без нормализации префикс не совпадёт вовсе.
     */
    public static function relativePath(string $file): string
    {
        $normalized = str_replace('\\', '/', $file);

        return str_starts_with($normalized, APP_ROOT) ? ltrim(mb_substr($normalized, mb_strlen(APP_ROOT)), '/') : $normalized;
    }

    /**
     * Тип запроса по первому слову — панель раскрашивает по нему бейджи.
     */
    private static function queryType(string $sql): string
    {
        $word = strtoupper((string) preg_replace('/^\s*(\w+).*/su', '$1', $sql));

        return in_array($word, ['SELECT', 'INSERT', 'UPDATE', 'DELETE'], true) ? $word : 'OTHER';
    }

    /**
     * Кто позвал запрос — без кадров внутри самого Connection: они одинаковы
     * у каждого запроса и не говорят, откуда он в приложении. Аргументы вызова
     * не собираем нарочно: там может быть пароль или токен, а этот же путь
     * ведёт и в лог.
     *
     * @return array<int, array{file: string, line: int, call: string}>
     */
    private static function trace(): array
    {
        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 20);
        $result = [];

        foreach ($frames as $frame) {
            $file = $frame['file'] ?? null;

            if ($file === null
                || str_contains($file, DIRECTORY_SEPARATOR . 'Database' . DIRECTORY_SEPARATOR . 'Connection.php')
                || str_contains($file, DIRECTORY_SEPARATOR . 'Support' . DIRECTORY_SEPARATOR . 'Profiler.php')
            ) {
                continue;
            }

            $call = ($frame['function'] ?? '') !== ''
                ? (($frame['class'] ?? '') !== '' ? $frame['class'] . ($frame['type'] ?? '::') . $frame['function'] : $frame['function'])
                : '';

            $result[] = [
                'file' => self::relativePath($file),
                'line' => (int) ($frame['line'] ?? 0),
                'call' => $call,
            ];

            if (count($result) >= 8) {
                break;
            }
        }

        return $result;
    }

    /**
     * Все собранные запросы.
     *
     * @return array<int, array{sql: string, ms: float, rows: int, offset: float, type: string, trace: array<int, array{file: string, line: int, call: string}>}>
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
     * @return array<int, array{sql: string, ms: float, rows: int, offset: float, type: string, trace: array<int, array{file: string, line: int, call: string}>}>
     */
    public static function slowest(int $limit = 5): array
    {
        $queries = self::$queries;

        usort($queries, static fn (array $a, array $b): int => $b['ms'] <=> $a['ms']);

        return array_slice($queries, 0, max(1, $limit));
    }

    /**
     * Обращение к Support\Cache: попало значение или пришлось считать заново.
     * Запросы через Query::remember() тоже сюда попадают — это и есть кэш
     * результата запроса, только с другой стороны.
     */
    public static function cache(string $key, bool $hit): void
    {
        if (!self::enabled() || count(self::$cache) >= 500) {
            return;
        }

        self::$cache[] = ['key' => $key, 'hit' => $hit];
    }

    /**
     * @return array<int, array{key: string, hit: bool}>
     */
    public static function cacheEntries(): array
    {
        return self::$cache;
    }

    /**
     * @return array{hits: int, misses: int}
     */
    public static function cacheStats(): array
    {
        $hits = 0;

        foreach (self::$cache as $entry) {
            if ($entry['hit']) {
                $hits++;
            }
        }

        return ['hits' => $hits, 'misses' => count(self::$cache) - $hits];
    }

    /**
     * Контрольная точка жизненного цикла запроса: вход и выход из прослойки,
     * начало и конец контроллера. По соседним отметкам на панели видно,
     * сколько заняла каждая — это и есть общий разбор «как отработал PHP»,
     * а не только сколько ушло на базу. $location — файл и строка класса или
     * функции, которая реально выполнялась: имя прослойки («auth») само по
     * себе не говорит, какой файл за ним стоит.
     */
    public static function mark(string $label, string $location = ''): void
    {
        if (!self::enabled() || count(self::$marks) >= 500) {
            return;
        }

        self::$marks[] = [
            'label'    => $label,
            'location' => $location,
            'offset'   => self::$started > 0 ? round((microtime(true) - self::$started) * 1000, 2) : 0.0,
        ];
    }

    /**
     * @return array<int, array{label: string, location: string, offset: float}>
     */
    public static function marks(): array
    {
        return self::$marks;
    }

    /**
     * Забыть собранное — нужно тестам и воркеру: он живёт часами.
     */
    public static function reset(): void
    {
        self::$queries = [];
        self::$cache   = [];
        self::$marks   = [];
        self::$started = microtime(true);
    }
}
