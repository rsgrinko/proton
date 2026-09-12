<?php

declare(strict_types=1);

/**
 * Запускальщик тестов: php bin/proton test (он же php tests/run.php).
 *
 * Никакого PHPUnit: тест объявляется функцией test(), проверки делаются
 * assertTrue, assertSame и подобными. Возвращает 0, если всё хорошо.
 *
 * Опции:
 *   --filter=строка     гонять только тесты, у которых строка есть в имени или в имени файла
 *   --stop-on-failure   остановиться на первой ошибке
 *   --shuffle[=seed]    перемешать порядок (ловит тесты, зависящие от соседей)
 *   --slow=ms           с какого времени тест считается медленным (по умолчанию 500)
 *
 * База всегда своя — SQLite в памяти; настройки DB_* из .env не читаются вовсе:
 * тесты заводят и удаляют записи целыми таблицами, и боевой базе тут делать
 * нечего. MySQL включается только переменными TEST_DB_* и только на отдельной базе.
 */

if (!defined('APP_ROOT')) {
    require dirname(__DIR__) . '/bootstrap.php';
}

use Rsgrinko\Proton\Database\Connection;
use Rsgrinko\Proton\Database\Migrator;
use Rsgrinko\Proton\Support\Config;
use Rsgrinko\Proton\Support\Container;
use Rsgrinko\Proton\Support\Env;

/** @var array<string, string> Опции запуска: от bin/proton test или из своего argv */
$options = $GLOBALS['test_options'] ?? [];

if ($options === [] && PHP_SAPI === 'cli') {
    foreach (array_slice($_SERVER['argv'] ?? [], 1) as $argument) {
        if (str_starts_with((string) $argument, '--')) {
            $argument = substr((string) $argument, 2);

            [$name, $value] = str_contains($argument, '=') ? explode('=', $argument, 2) : [$argument, '1'];

            $options[$name] = $value;
        }
    }
}

$GLOBALS['test_options'] = $options;

// Своё у прогона не только подключение: логи и временные файлы тоже, иначе
// тесты, которые нарочно роняют базу, подмешивают свои ошибки в рабочий журнал
Config::set('log.level', 'error');
Config::set('paths.log', APP_ROOT . '/var/log/tests');
Config::set('paths.cache', APP_ROOT . '/var/tmp/cache-tests');
Config::set('paths.storage', APP_ROOT . '/var/tmp/storage-tests');
Config::set('mail.driver', 'null');
Config::set('mail.from_email', 'tests@example.com');
Config::set('app.key', 'base64:' . base64_encode(str_repeat('t', 32)));

/**
 * Настройки базы для тестов.
 *
 * @return array<string, mixed>
 */
function testDatabaseSettings(string $database = ''): array
{
    if (strtolower(Env::string('TEST_DB_DRIVER', 'sqlite')) !== 'mysql') {
        return ['driver' => 'sqlite', 'sqlite' => ['path' => ':memory:']];
    }

    $name = $database !== '' ? $database : Env::string('TEST_DB_DATABASE', '');
    $host = Env::string('TEST_DB_HOST', '127.0.0.1');

    testRefuseProductionDatabase($host, $name);

    return [
        'driver' => 'mysql',
        'mysql'  => [
            'host'     => $host,
            'port'     => Env::int('TEST_DB_PORT', 3306),
            'database' => $name,
            'username' => Env::string('TEST_DB_USERNAME', 'root'),
            'password' => Env::string('TEST_DB_PASSWORD', ''),
            'charset'  => Env::string('TEST_DB_CHARSET', 'utf8mb4'),
        ],
    ];
}

/**
 * Последняя преграда перед боевой базой: совпало имя с тем, что в .env, —
 * прогон не начинается вовсе. Хост не спасает: его слишком легко обойти
 * localhost'ом или пробросом порта.
 */
function testRefuseProductionDatabase(string $host, string $name): void
{
    $stop = static function (string $message): void {
        fwrite(STDERR, 'Тесты остановлены: ' . $message . PHP_EOL);

        exit(1);
    };

    if (trim($name) === '') {
        $stop('с TEST_DB_DRIVER=mysql нужно задать TEST_DB_DATABASE — отдельную базу для тестов');
    }

    $production = testProductionDatabase();

    if ($production !== '' && strcasecmp(trim($name), trim($production)) === 0) {
        $stop('TEST_DB_DATABASE совпадает с базой из .env («' . $production . '»). Заведите отдельную: тесты сносят записи целыми таблицами');
    }

    if (trim($host) === '') {
        $stop('не задан TEST_DB_HOST');
    }
}

/**
 * Имя боевой базы из .env — то, с чем нельзя совпадать тестовой. Запоминается
 * до подмены настроек, иначе сверка шла бы сама с собой.
 */
function testProductionDatabase(): string
{
    static $name = null;

    if ($name === null) {
        $name = (string) Config::get('db.mysql.database', '');
    }

    return $name;
}

testProductionDatabase();

$testSettings = testDatabaseSettings();

Config::set('db.driver', $testSettings['driver']);

if ($testSettings['driver'] === 'sqlite') {
    Config::set('db.sqlite.path', ':memory:');
} else {
    Config::set('db.mysql', $testSettings['mysql']);
}

$testDatabase = new Connection($testSettings);

Connection::setInstance($testDatabase);

if ($testSettings['driver'] === 'mysql') {
    testDropTables($testDatabase);
}

(new Migrator())->run();

/**
 * Сносит все таблицы: прогон должен начинаться с чистой схемы.
 */
function testDropTables(Connection $database): void
{
    $tables = $database->tables();

    if ($tables === []) {
        return;
    }

    $database->execute('SET FOREIGN_KEY_CHECKS = 0');

    try {
        foreach ($tables as $table) {
            $database->execute('DROP TABLE IF EXISTS `' . str_replace('`', '', $table) . '`');
        }
    } finally {
        $database->execute('SET FOREIGN_KEY_CHECKS = 1');
    }
}

/**
 * Отдельная база-файл на SQLite: со схемой, но пустая. Нужна там, где базу
 * должен увидеть другой процесс — база в памяти живёт только внутри своего
 * подключения. Файл удаляется после прогона.
 */
function testDatabaseFile(string $name): string
{
    static $counter = 0;

    $path = (string) Config::get('paths.tmp', APP_ROOT . '/var/tmp')
        . '/test-' . $name . '-' . getmypid() . '-' . ++$counter . '.sqlite';

    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0775, true);
    }

    foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }

    $database = new Connection(['driver' => 'sqlite', 'sqlite' => ['path' => $path]]);

    $current = Connection::instance();

    Connection::setInstance($database);

    try {
        (new Migrator($database))->run();
    } finally {
        Connection::setInstance($current);
    }

    afterTests(static function () use ($path): void {
        foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    });

    return $path;
}

$GLOBALS['tests']    = [];
$GLOBALS['cleanups'] = [];
$GLOBALS['failed']   = 0;
$GLOBALS['passed']   = 0;
$GLOBALS['skipped']  = 0;

/**
 * Объявить тест. Файл запоминаем, чтобы работал --filter=QueueTest.
 */
function test(string $name, callable $body): void
{
    $GLOBALS['tests'][] = [
        'name' => $name,
        'body' => $body,
        'file' => basename((string) ($GLOBALS['test_file'] ?? '')),
    ];
}

/**
 * Отложить уборку до конца прогона — даже если тест упал.
 *
 * Отдельным тестом уборку не пишут: с --shuffle она уедет в середину
 * и унесёт чужие данные.
 */
function afterTests(callable $callback): void
{
    $GLOBALS['cleanups'][] = $callback;
}

/**
 * Настройки на время теста: возвращаются в любом случае.
 *
 * @param array<string, mixed> $values
 */
function withConfig(array $values, callable $body): mixed
{
    $previous = [];

    foreach ($values as $key => $value) {
        $previous[$key] = Config::get($key);

        Config::set($key, $value);
    }

    try {
        return $body();
    } finally {
        foreach ($previous as $key => $value) {
            Config::set($key, $value);
        }
    }
}

/**
 * Своя чистая база со схемой — для тестов-хозяев, которые сносят таблицы
 * целиком. На общей базе такой тест зависит от соседей и мешает им.
 */
function withOwnDatabase(callable $body): mixed
{
    $settings = testDatabaseSettings();
    $own      = new Connection($settings['driver'] === 'mysql' ? testOwnMysqlSettings() : $settings);

    $previous = Connection::instance();

    Connection::setInstance($own);

    // Контейнер держит собранные объекты, а они запомнили прежнее подключение
    Container::setInstance(null);

    try {
        if ($settings['driver'] === 'mysql') {
            testDropTables($own);
        }

        (new Migrator($own))->run();

        return $body($own);
    } finally {
        Connection::setInstance($previous);
        Container::setInstance(null);
    }
}

/**
 * Вторая база MySQL под тесты-хозяева: в памяти MySQL баз не держит.
 *
 * @return array<string, mixed>
 */
function testOwnMysqlSettings(): array
{
    $name     = Env::string('TEST_DB_DATABASE', '') . '_own';
    $settings = testDatabaseSettings($name);

    $server = $settings;

    $server['mysql']['database'] = '';

    (new Connection($server))->execute(
        'CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '', $name) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
    );

    return $settings;
}

/**
 * Ошибка проверки.
 */
class TestFailure extends RuntimeException
{
}

/**
 * Тест нельзя выполнить в этом окружении.
 */
class TestSkipped extends RuntimeException
{
}

function skipTest(string $reason): never
{
    throw new TestSkipped($reason);
}

function assertTrue(bool $value, string $message = 'ожидалось true'): void
{
    if (!$value) {
        throw new TestFailure($message);
    }
}

function assertFalse(bool $value, string $message = 'ожидалось false'): void
{
    assertTrue(!$value, $message);
}

function assertSame(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new TestFailure(
            ($message !== '' ? $message . ': ' : '')
            . 'ожидалось ' . var_export($expected, true) . ', получено ' . var_export($actual, true)
        );
    }
}

function assertNull(mixed $value, string $message = 'ожидался null'): void
{
    if ($value !== null) {
        throw new TestFailure($message . ', получено ' . var_export($value, true));
    }
}

/**
 * Значение есть — и заодно возвращается, чтобы не писать проверку и выборку дважды.
 */
function assertNotNull(mixed $value, string $message = 'значение не должно быть null'): mixed
{
    if ($value === null) {
        throw new TestFailure($message);
    }

    return $value;
}

function assertCount(int $expected, array|Countable $items, string $message = ''): void
{
    $actual = count($items);

    if ($actual !== $expected) {
        throw new TestFailure(
            ($message !== '' ? $message . ': ' : '') . 'ожидалось записей ' . $expected . ', получено ' . $actual
        );
    }
}

function assertContains(string $needle, string $haystack, string $message = ''): void
{
    if (!str_contains($haystack, $needle)) {
        throw new TestFailure(($message !== '' ? $message . ': ' : '') . 'в строке нет фрагмента «' . $needle . '»');
    }
}

function assertNotContains(string $needle, string $haystack, string $message = ''): void
{
    if (str_contains($haystack, $needle)) {
        throw new TestFailure(($message !== '' ? $message . ': ' : '') . 'в строке не должно быть фрагмента «' . $needle . '»');
    }
}

function assertMatches(string $pattern, string $subject, string $message = ''): void
{
    if (preg_match($pattern, $subject) !== 1) {
        throw new TestFailure(
            ($message !== '' ? $message . ': ' : '') . 'строка не подошла под ' . $pattern . ': ' . mb_substr($subject, 0, 200)
        );
    }
}

/**
 * Код ответа. При расхождении показывает начало тела — сразу видно, что
 * вернулось на самом деле: страница ошибки, редирект или JSON с описанием.
 */
function assertStatus(int $expected, Rsgrinko\Proton\Http\Response $response, string $message = ''): void
{
    if ($response->status() === $expected) {
        return;
    }

    $body = trim(strip_tags($response->body()));
    $body = preg_replace('/\s+/u', ' ', $body) ?? '';

    throw new TestFailure(
        ($message !== '' ? $message . ': ' : '')
        . 'ожидался код ' . $expected . ', получен ' . $response->status()
        . ($body === '' ? '' : ' — ' . mb_substr($body, 0, 200))
    );
}

/**
 * Проверяет, что код бросает исключение.
 */
function assertThrows(callable $callback, string $message = 'ожидалось исключение'): Throwable
{
    try {
        $callback();
    } catch (Throwable $e) {
        return $e;
    }

    throw new TestFailure($message);
}

/**
 * Где сработала проверка: первый кадр стека вне самого раннера.
 */
function testLocation(Throwable $e): string
{
    $frames = array_merge([['file' => $e->getFile(), 'line' => $e->getLine()]], $e->getTrace());

    foreach ($frames as $frame) {
        $file = (string) ($frame['file'] ?? '');

        if ($file !== '' && basename($file) !== 'run.php') {
            return basename($file) . ':' . (int) ($frame['line'] ?? 0);
        }
    }

    return basename((string) $e->getFile()) . ':' . $e->getLine();
}

// Предупреждение PHP — это ошибка теста: «Undefined array key» во вьюхе
// не должен молча уезжать в вывод. Подавленное через @ пропускаем
error_reporting(E_ALL);

set_error_handler(static function (int $level, string $message, string $file, int $line): bool {
    if ((error_reporting() & $level) === 0) {
        return false;
    }

    $names = [
        E_WARNING           => 'предупреждение',
        E_NOTICE            => 'замечание',
        E_DEPRECATED        => 'устаревшее',
        E_USER_WARNING      => 'предупреждение',
        E_USER_NOTICE       => 'замечание',
        E_USER_ERROR        => 'ошибка',
        E_RECOVERABLE_ERROR => 'ошибка',
    ];

    throw new TestFailure(($names[$level] ?? 'ошибка') . ' PHP: ' . $message . ' (' . basename($file) . ':' . $line . ')');
});

foreach (glob(__DIR__ . '/*Test.php') ?: [] as $file) {
    $GLOBALS['test_file'] = $file;

    require $file;
}

unset($GLOBALS['test_file']);

$filter = (string) ($options['filter'] ?? '');

if ($filter !== '') {
    $GLOBALS['tests'] = array_values(array_filter(
        $GLOBALS['tests'],
        static fn (array $test): bool => mb_stripos($test['name'], $filter) !== false
            || stripos($test['file'], $filter) !== false
    ));
}

if (isset($options['shuffle'])) {
    $seed = $options['shuffle'] === '1' ? random_int(1, 999999) : (int) $options['shuffle'];

    mt_srand($seed);

    $order = $GLOBALS['tests'];

    shuffle($order);

    $GLOBALS['tests'] = $order;

    echo 'Порядок перемешан, seed=' . $seed . ' (повторить: --shuffle=' . $seed . ')' . PHP_EOL;
}

$stopOnFailure = isset($options['stop-on-failure']);
$slow          = (int) ($options['slow'] ?? 500);

echo 'Запускаю тестов: ' . count($GLOBALS['tests']) . PHP_EOL . PHP_EOL;

$started = microtime(true);

foreach ($GLOBALS['tests'] as $test) {
    $at = microtime(true);

    try {
        ($test['body'])();

        $GLOBALS['passed']++;

        $spent = (int) round((microtime(true) - $at) * 1000);

        echo '  ok      ' . $test['name'] . ($spent >= $slow ? ' (' . $spent . ' мс)' : '') . PHP_EOL;
    } catch (TestSkipped $e) {
        $GLOBALS['skipped']++;

        echo '  пропуск ' . $test['name'] . ' (' . $e->getMessage() . ')' . PHP_EOL;
    } catch (Throwable $e) {
        $GLOBALS['failed']++;

        echo '  ОШИБКА  ' . $test['name'] . PHP_EOL;
        echo '          ' . $e->getMessage() . PHP_EOL;
        echo '          ' . ($e instanceof TestFailure ? '' : get_class($e) . ' в ') . testLocation($e) . PHP_EOL;

        if ($stopOnFailure) {
            echo PHP_EOL . 'Остановлено на первой ошибке (--stop-on-failure)' . PHP_EOL;

            break;
        }
    }
}

// Уборка идёт после всех тестов и в обратном порядке: сначала убирается то,
// что завели позже
foreach (array_reverse($GLOBALS['cleanups']) as $cleanup) {
    try {
        $cleanup();
    } catch (Throwable $e) {
        echo '  уборка не удалась: ' . $e->getMessage() . PHP_EOL;
    }
}

// Временные каталоги прогона за собой тоже убираем
foreach ([APP_ROOT . '/var/tmp/cache-tests', APP_ROOT . '/var/tmp/storage-tests'] as $dir) {
    foreach ((array) glob($dir . '/*') as $file) {
        if (is_string($file) && is_file($file)) {
            @unlink($file);
        }
    }
}

restore_error_handler();

echo PHP_EOL . 'Успешно: ' . $GLOBALS['passed']
    . ', с ошибками: ' . $GLOBALS['failed']
    . ', пропущено: ' . $GLOBALS['skipped']
    . ', времени: ' . number_format(microtime(true) - $started, 1, ',', ' ') . ' с' . PHP_EOL;

return $GLOBALS['failed'] > 0 ? 1 : 0;
