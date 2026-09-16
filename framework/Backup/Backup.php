<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Backup;

use PDO;
use Rsgrinko\Proton\Database\Connection;
use Rsgrinko\Proton\Support\Config;
use Rsgrinko\Proton\Support\Logger;
use Rsgrinko\Proton\Support\ProtonException;
use Rsgrinko\Proton\Support\Str;
use Throwable;

/**
 * Резервная копия базы: сделать, проверить, сложить в `var/backups`, убрать старые.
 *
 * Внешних утилит не зовём — ни mysqldump, ни gzip: их может не быть, а `exec()`
 * на хостинге часто закрыт. SQLite копируется средствами самой базы
 * (`VACUUM INTO`: копия получается целостной даже под нагрузкой), MySQL
 * выгружается обычными запросами. Файл сразу пишется сжатым.
 *
 * Копия без проверки — не копия: сразу после создания файл открывается заново
 * и читается. Для SQLite это `PRAGMA integrity_check` и пересчёт таблиц, для
 * дампа MySQL — разбор текста: столько ли таблиц и дошёл ли файл до конца.
 */
final class Backup
{
    /** Маркер конца дампа: по нему видно, что файл не обрезан */
    private const TAIL = '-- конец дампа';

    /** Сколько строк выгружаем одним запросом */
    private const CHUNK = 500;

    /**
     * Делает копию. Возвращает сведения о ней.
     *
     * @return array{name: string, size: int, driver: string, tables: int, ms: int}
     */
    public static function create(): array
    {
        // Свои временные файлы проверки подчищаем на каждом запуске: unlink
        // в verifySqlite() иногда не срабатывает (на Windows PDO не всегда
        // отпускает файл сразу же), а тихая ошибка — это тихая утечка
        self::purgeTemp();

        $started = microtime(true);
        $db      = Connection::instance();
        $dir     = self::directory();

        // Хвост из случайных символов обязателен: две копии за одну секунду
        // (а так бывает — перед восстановлением делается страховочная) иначе
        // получили бы одно имя, и вторая затёрла бы первую
        $name = 'proton-' . date('Y-m-d-His') . '-' . Str::random(4) . '-' . $db->driver()
            . ($db->isSqlite() ? '.sqlite.gz' : '.sql.gz');

        $path = $dir . '/' . $name;

        try {
            $tables = $db->isSqlite() ? self::dumpSqlite($db, $path) : self::dumpMysql($db, $path);
        } catch (Throwable $e) {
            // Недоделанную копию не оставляем: её потом примут за годную
            if (is_file($path)) {
                @unlink($path);
            }

            throw $e;
        }

        $check = self::verify($name);

        if (!$check['ok']) {
            @unlink($path);

            throw new ProtonException('Копия не прошла проверку: ' . $check['message']);
        }

        $info = [
            'name'   => $name,
            'size'   => (int) filesize($path),
            'driver' => $db->driver(),
            'tables' => $tables,
            'ms'     => (int) round((microtime(true) - $started) * 1000),
        ];

        (new Logger('backup'))->info('Копия базы сделана', $info);

        return $info;
    }

    /**
     * Проверяет готовую копию: её и правда можно открыть и прочитать.
     *
     * @return array{ok: bool, message: string, tables: int}
     */
    public static function verify(string $name): array
    {
        $path = self::resolve($name);

        if (!is_file($path)) {
            return ['ok' => false, 'message' => 'файла нет', 'tables' => 0];
        }

        if (filesize($path) === 0) {
            return ['ok' => false, 'message' => 'файл пустой', 'tables' => 0];
        }

        return str_ends_with($name, '.sqlite.gz')
            ? self::verifySqlite($path)
            : self::verifyDump($path);
    }

    /**
     * Копии на диске, новые сверху.
     *
     * @return array<int, array{name: string, size: int, created: string}>
     */
    public static function files(): array
    {
        $files = [];

        foreach (glob(self::directory() . '/*.gz') ?: [] as $path) {
            $files[] = [
                'name'    => basename($path),
                'size'    => (int) filesize($path),
                'created' => date('Y-m-d H:i:s', (int) filemtime($path)),
            ];
        }

        usort($files, static fn (array $a, array $b): int => strcmp($b['name'], $a['name']));

        return $files;
    }

    /**
     * Содержимое копии — чтобы отдать её на скачивание.
     */
    public static function read(string $name): string
    {
        return (string) file_get_contents(self::resolve($name));
    }

    public static function delete(string $name): bool
    {
        $path = self::resolve($name);

        return is_file($path) && unlink($path);
    }

    /**
     * Подчищает зависшие временные копии за собой (var/tmp/backup-*.sqlite и
     * недоведённое до конца восстановление). Возвращает, сколько убрал.
     *
     * Свежие (младше часа) не трогает: может идти параллельная проверка.
     */
    public static function purgeTemp(int $olderThanMinutes = 60): int
    {
        $dir = (string) Config::get('paths.tmp', APP_ROOT . '/var/tmp');
        $edge = time() - max(1, $olderThanMinutes) * 60;
        $removed = 0;

        foreach ((array) glob($dir . '/backup-*.sqlite') as $file) {
            $file = (string) $file;

            if (is_file($file) && filemtime($file) < $edge && @unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Оставляет только последние копии. Возвращает, сколько удалил.
     */
    public static function rotate(?int $keep = null): int
    {
        $keep = $keep ?? (int) Config::get('backup.keep', 7);

        if ($keep <= 0) {
            return 0;
        }

        $files   = self::files();
        $removed = 0;

        foreach (array_slice($files, $keep) as $file) {
            if (self::delete($file['name'])) {
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Каталог с копиями, создаётся при первом обращении.
     */
    public static function directory(): string
    {
        $dir = (string) Config::get('backup.path', APP_ROOT . '/var/backups');

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new ProtonException('Не удалось создать каталог копий: ' . $dir);
        }

        return rtrim(str_replace('\\', '/', $dir), '/');
    }

    /**
     * Полный путь к копии. Имя проверяем: по нему ходят из панели, и «..»
     * в нём означало бы чтение и удаление чужих файлов.
     */
    public static function resolve(string $name): string
    {
        if (preg_match('/^[A-Za-z0-9._-]+\.gz$/', $name) !== 1) {
            throw new ProtonException('Недопустимое имя копии: ' . $name);
        }

        return self::directory() . '/' . $name;
    }

    /**
     * Восстановление из копии.
     *
     * Вещь крайняя: текущие данные заменяются целиком. Поэтому перед работой
     * делается копия того, что есть сейчас, — если «восстановили не то», будет
     * куда вернуться.
     *
     * @return array{tables: int, backup: string}
     */
    public static function restore(string $name): array
    {
        $path = self::resolve($name);

        if (!is_file($path)) {
            throw new ProtonException('Копии нет: ' . $name);
        }

        $check = self::verify($name);

        if (!$check['ok']) {
            throw new ProtonException('Копия негодна: ' . $check['message']);
        }

        // Сначала страховка: то, что сейчас в базе, тоже уходит в копию
        $safety = self::create();

        $sqlite = Connection::instance()->isSqlite();

        if ($sqlite !== str_ends_with($name, '.sqlite.gz')) {
            throw new ProtonException('Копия сделана с другой СУБД: её так не восстановить');
        }

        if ($sqlite) {
            $target = Connection::instance()->sqlitePath();

            // Соединение отпускаем до замены файла: под Windows открытый файл
            // не переименовать, да и старый PDO после подмены всё равно негоден
            Connection::setInstance(null);

            $tables = self::restoreSqlite($target, $path);
        } else {
            $tables = self::restoreMysql(Connection::instance(), $path);
        }

        (new Logger('backup'))->warning('База восстановлена из копии', [
            'from'   => $name,
            'safety' => $safety['name'],
            'tables' => $tables,
        ]);

        return ['tables' => $tables, 'backup' => $safety['name']];
    }

    // --- SQLite --------------------------------------------------------------

    /**
     * Путь к временному файлу во временном каталоге.
     */
    private static function temporary(string $suffix): string
    {
        $dir = (string) Config::get('paths.tmp', APP_ROOT . '/var/tmp');

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new ProtonException('Не удалось создать временный каталог: ' . $dir);
        }

        return rtrim(str_replace('\\', '/', $dir), '/') . '/backup-' . $suffix . '-' . Str::random(8) . '.sqlite';
    }

    /**
     * Копия файла базы силами самой SQLite, потом сжатие.
     */
    private static function dumpSqlite(Connection $db, string $path): int
    {
        $plain = self::temporary('dump');

        if (is_file($plain)) {
            @unlink($plain);
        }

        // VACUUM INTO делает согласованную копию, не останавливая работу
        $db->pdo()->exec("VACUUM INTO '" . str_replace("'", "''", $plain) . "'");

        self::compress($plain, $path);

        @unlink($plain);

        return count($db->tables());
    }

    /**
     * @return array{ok: bool, message: string, tables: int}
     */
    private static function verifySqlite(string $path): array
    {
        // Разжатая копия — во временный каталог, а не рядом с копиями: там
        // должны лежать только сами копии
        $plain = self::temporary('check');
        $pdo   = null;

        try {
            self::decompress($path, $plain);

            $pdo = new PDO('sqlite:' . $plain, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

            $result = (string) $pdo->query('PRAGMA integrity_check')->fetchColumn();

            if ($result !== 'ok') {
                return ['ok' => false, 'message' => 'integrity_check: ' . $result, 'tables' => 0];
            }

            $tables = (int) $pdo->query(
                "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"
            )->fetchColumn();

            if ($tables === 0) {
                return ['ok' => false, 'message' => 'в копии нет таблиц', 'tables' => 0];
            }

            return ['ok' => true, 'message' => 'таблиц: ' . $tables, 'tables' => $tables];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'tables' => 0];
        } finally {
            // Соединение отпускаем до удаления: Windows не даёт убрать открытый файл
            $pdo = null;

            if (is_file($plain)) {
                @unlink($plain);
            }
        }
    }

    private static function restoreSqlite(string $target, string $path): int
    {
        if ($target === '' || $target === ':memory:') {
            throw new ProtonException('Базу в памяти восстанавливать некуда');
        }

        $plain = $target . '.restore';

        self::decompress($path, $plain);

        if (!@rename($plain, $target)) {
            @unlink($plain);

            throw new ProtonException('Не удалось заменить файл базы: ' . $target);
        }

        return count(Connection::instance()->tables());
    }

    // --- MySQL ---------------------------------------------------------------

    /**
     * Свой дамп: структура из SHOW CREATE TABLE, данные — порциями.
     */
    private static function dumpMysql(Connection $db, string $path): int
    {
        $handle = gzopen($path, 'wb9');

        if ($handle === false) {
            throw new ProtonException('Не удалось открыть файл копии: ' . $path);
        }

        $tables = $db->tables();

        gzwrite($handle, "-- Proton, копия базы от " . date('Y-m-d H:i:s') . "\n");
        gzwrite($handle, "-- таблиц: " . count($tables) . "\n");
        gzwrite($handle, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n");

        foreach ($tables as $table) {
            $create = $db->selectOne('SHOW CREATE TABLE `' . $table . '`');

            gzwrite($handle, 'DROP TABLE IF EXISTS `' . $table . "`;\n");
            gzwrite($handle, (string) ($create['Create Table'] ?? '') . ";\n\n");

            $offset = 0;

            do {
                $rows = $db->select('SELECT * FROM `' . $table . '` LIMIT ' . self::CHUNK . ' OFFSET ' . $offset);

                foreach ($rows as $row) {
                    gzwrite($handle, self::insert($db, $table, $row));
                }

                $offset += self::CHUNK;
            } while (count($rows) === self::CHUNK);

            gzwrite($handle, "\n");
        }

        gzwrite($handle, "SET FOREIGN_KEY_CHECKS = 1;\n" . self::TAIL . "\n");
        gzclose($handle);

        return count($tables);
    }

    /**
     * Одна строка INSERT со значениями, экранированными самим драйвером.
     *
     * @param array<string, mixed> $row
     */
    private static function insert(Connection $db, string $table, array $row): string
    {
        $values = [];

        foreach ($row as $value) {
            $values[] = $value === null ? 'NULL' : $db->pdo()->quote((string) $value);
        }

        $columns = implode('`, `', array_keys($row));

        return 'INSERT INTO `' . $table . '` (`' . $columns . '`) VALUES (' . implode(', ', $values) . ");\n";
    }

    /**
     * @return array{ok: bool, message: string, tables: int}
     */
    private static function verifyDump(string $path): array
    {
        $handle = gzopen($path, 'rb');

        if ($handle === false) {
            return ['ok' => false, 'message' => 'файл не читается', 'tables' => 0];
        }

        $tables = 0;
        $tail   = false;

        while (!gzeof($handle)) {
            $line = (string) gzgets($handle, 4096);

            if (str_starts_with($line, 'CREATE TABLE')) {
                $tables++;
            }

            if (str_starts_with($line, self::TAIL)) {
                $tail = true;
            }
        }

        gzclose($handle);

        if (!$tail) {
            return ['ok' => false, 'message' => 'дамп обрезан: нет маркера конца', 'tables' => $tables];
        }

        if ($tables === 0) {
            return ['ok' => false, 'message' => 'в дампе нет таблиц', 'tables' => 0];
        }

        return ['ok' => true, 'message' => 'таблиц: ' . $tables, 'tables' => $tables];
    }

    private static function restoreMysql(Connection $db, string $path): int
    {
        $handle = gzopen($path, 'rb');

        if ($handle === false) {
            throw new ProtonException('Копия не читается: ' . $path);
        }

        $statement = '';
        $applied   = 0;

        while (!gzeof($handle)) {
            $line = (string) gzgets($handle, 8192);

            if (trim($line) === '' || str_starts_with($line, '--')) {
                continue;
            }

            $statement .= $line;

            // Запрос кончается точкой с запятой в конце строки
            if (!str_ends_with(rtrim($statement), ';')) {
                continue;
            }

            $db->pdo()->exec(rtrim($statement));

            if (str_contains($statement, 'CREATE TABLE')) {
                $applied++;
            }

            $statement = '';
        }

        gzclose($handle);

        return $applied;
    }

    // --- Сжатие --------------------------------------------------------------

    private static function compress(string $from, string $to): void
    {
        $in  = fopen($from, 'rb');
        $out = gzopen($to, 'wb9');

        if ($in === false || $out === false) {
            throw new ProtonException('Не удалось сжать копию: ' . $from);
        }

        // Закрываем в finally: иначе на ошибке чтения файл остаётся открытым,
        // и под Windows его потом не удалить
        try {
            while (!feof($in)) {
                gzwrite($out, (string) fread($in, 262144));
            }
        } finally {
            fclose($in);
            gzclose($out);
        }
    }

    private static function decompress(string $from, string $to): void
    {
        $in  = gzopen($from, 'rb');
        $out = fopen($to, 'wb');

        if ($in === false || $out === false) {
            throw new ProtonException('Не удалось разжать копию: ' . $from);
        }

        try {
            while (!gzeof($in)) {
                fwrite($out, (string) gzread($in, 262144));
            }
        } finally {
            gzclose($in);
            fclose($out);
        }
    }

    /**
     * Человеческий размер копии — показывает панель и консоль.
     */
    public static function size(int $bytes): string
    {
        return Str::bytes($bytes);
    }
}
