<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Core;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Rsgrinko\Proton\Support\HttpClient;
use Rsgrinko\Proton\Support\ProtonException;

/**
 * Перенос обновлений ядра в проект: контрольные суммы вместо археологии по
 * git log (`docs/CORE_UPDATES.md`), сверка ссылок в app/config/routes/
 * resources/views перед тем, как предложить что-то удалить. Команды `core:*` —
 * тонкая обвязка ввода-вывода поверх этого класса, вся логика — здесь.
 */
final class Updater
{
    /** Каталоги проекта, которые не входят в ядро, — их код может ссылаться на классы ядра */
    private const CLIENT_DIRS = ['app', 'config', 'routes', 'resources/views'];

    private const BASELINE_FILE = 'framework/.checksums.json';

    private const VERSION_FILE = 'framework/VERSION';

    public function __construct(private readonly string $root)
    {
    }

    public function localVersion(): string
    {
        return $this->readVersion($this->root);
    }

    public function readVersion(string $projectRoot): string
    {
        $file = rtrim($projectRoot, '/') . '/' . self::VERSION_FILE;

        return is_file($file) ? trim((string) file_get_contents($file)) : '0.0.0';
    }

    /**
     * Следующая версия по правилам семвера: минор сбрасывает патч, мажор —
     * минор и патч.
     */
    public static function nextVersion(string $version, string $level): string
    {
        [$major, $minor, $patch] = array_pad(array_map('intval', explode('.', $version)), 3, 0);

        return match ($level) {
            'major' => ($major + 1) . '.0.0',
            'minor' => $major . '.' . ($minor + 1) . '.0',
            default => $major . '.' . $minor . '.' . ($patch + 1),
        };
    }

    /**
     * Правит VERSION и дописывает запись в docs/CHANGELOG.md — оба места
     * иначе легко разъезжаются, если делать их по отдельности.
     */
    public function bump(string $level, string $note, bool $breaking): string
    {
        $new = self::nextVersion($this->localVersion(), $level);

        file_put_contents($this->root . '/' . self::VERSION_FILE, $new . "\n");

        if (trim($note) !== '') {
            $this->prependChangelog($new, trim($note), $breaking);
        }

        return $new;
    }

    private function prependChangelog(string $version, string $note, bool $breaking): void
    {
        $file  = $this->root . '/docs/CHANGELOG.md';
        $body  = is_file($file) ? (string) file_get_contents($file) : "# Журнал изменений ядра\n\n";
        $entry = "## {$version}\n" . ($breaking ? '- **Ломает:** ' : '- ') . $note . "\n\n";

        // Запись — перед самой свежей версией, а не сразу под заголовком:
        // между заголовком и первой версией может стоять вводный абзац
        if (preg_match('/^## /m', $body, $m, PREG_OFFSET_CAPTURE) === 1) {
            $body = substr_replace($body, $entry, $m[0][1], 0);
        } else {
            $body = rtrim($body) . "\n\n" . $entry;
        }

        file_put_contents($file, $body);
    }

    /**
     * Контрольная сумма каждого файла в framework/ — CRLF нормализуется, чтобы
     * не поймать ложное отличие только из-за перевода строки.
     *
     * @return array<string, string> относительный путь ("framework/...") => sha256
     */
    public function checksums(string $projectRoot): array
    {
        $base = rtrim($projectRoot, '/') . '/framework';
        $sums = [];

        if (!is_dir($base)) {
            return $sums;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $relative = 'framework/' . str_replace('\\', '/', substr($file->getPathname(), strlen($base) + 1));

            // Сам манифест и файл версии не участвуют в сверке — иначе любой
            // core:bump или core:check --baseline менял бы собственный результат
            if (in_array($relative, [self::BASELINE_FILE, self::VERSION_FILE], true)) {
                continue;
            }

            $content       = str_replace("\r\n", "\n", (string) file_get_contents($file->getPathname()));
            $sums[$relative] = hash('sha256', $content);
        }

        ksort($sums);

        return $sums;
    }

    /**
     * База — суммы ядра, от которого проект сейчас отсчитывается (общий
     * предок для трёхсторонней сверки), а не снимок своего framework/. Без
     * $corePath берётся свой framework/ — верно только пока в нём нет правок:
     * иначе патч попадёт в базу, станет невидим, и следующая правка этого
     * файла в ядре затрёт его как «безопасная».
     */
    public function saveBaseline(?string $corePath = null): void
    {
        $this->writeBaseline($this->checksums($corePath ?? $this->root));
    }

    /**
     * @param array<string, string> $sums
     */
    private function writeBaseline(array $sums): void
    {
        ksort($sums);

        file_put_contents(
            $this->root . '/' . self::BASELINE_FILE,
            json_encode($sums, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );
    }

    /** @return array<string, string> */
    public function storedBaseline(): array
    {
        $file = $this->root . '/' . self::BASELINE_FILE;

        if (!is_file($file)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data) ? $data : [];
    }

    /**
     * После sync база становится новым ядром — кроме файлов, которые ещё
     * ждут ручного слияния. У них остаётся старый предок: иначе недомерженный
     * файл выглядел бы как «ядро его не трогало, это свой патч», и правка
     * ядра в нём потерялась бы молча. Такой файл переводит на новое ядро
     * только resolve(), когда слияние сделано.
     *
     * @param array<int, string> $pending diff()['manual']
     */
    public function markSynced(string $corePath, array $pending): void
    {
        $old   = $this->storedBaseline();
        $new   = $this->checksums($corePath);
        $local = $this->checksums($this->root);

        foreach ($pending as $path) {
            if (isset($old[$path])) {
                $new[$path] = $old[$path];
            } else {
                unset($new[$path]);
            }
        }

        // Удалённое ядром, но ещё лежащее у нас, держим в базе — иначе оно
        // выпадет из списка «ядро больше не содержит» раньше, чем его уберут
        foreach (array_keys($local) as $path) {
            if (!isset($new[$path]) && isset($old[$path])) {
                $new[$path] = $old[$path];
            }
        }

        $this->writeBaseline($new);
    }

    /**
     * Отмечает файлы из ручного слияния разобранными: предком для них
     * становится версия ядра. Разница между своим файлом и ядром с этого
     * момента считается своим патчем, и следующий diff покажет файл снова,
     * только если ядро поменяет его ещё раз.
     *
     * @param array<int, string> $paths "framework/..." или путь внутри framework/
     *
     * @return array<int, string> пути, которых нет ни в ядре, ни в базе, — не тронуты
     */
    public function resolve(string $corePath, array $paths): array
    {
        $baseline = $this->storedBaseline();
        $core     = $this->checksums($corePath);
        $unknown  = [];

        foreach ($paths as $path) {
            $path = str_replace('\\', '/', ltrim($path, './\\'));

            if (!str_starts_with($path, 'framework/')) {
                $path = 'framework/' . $path;
            }

            if (isset($core[$path])) {
                $baseline[$path] = $core[$path];
            } elseif (isset($baseline[$path])) {
                unset($baseline[$path]); // ядро файл удалило — решение принято, предка больше нет
            } else {
                $unknown[] = $path;
            }
        }

        $this->writeBaseline($baseline);

        return $unknown;
    }

    /**
     * Ставит себе версию ядра — только когда ручное слияние разобрано
     * целиком. Иначе версия врала бы: файлы ещё от старого ядра.
     */
    public function adoptVersion(string $corePath): string
    {
        $version = $this->readVersion($corePath);

        file_put_contents($this->root . '/' . self::VERSION_FILE, $version . "\n");

        return $version;
    }

    /**
     * Файлы framework/, отличающиеся от ядра последней синхронизации: свои
     * патчи и неразобранное ручное слияние. К ядру не ходит — сверка с базой.
     *
     * @return array<int, string>
     */
    public function check(): array
    {
        $baseline = $this->storedBaseline();
        $current  = $this->checksums($this->root);
        $changed  = [];

        foreach ($current as $path => $hash) {
            if (isset($baseline[$path]) && $baseline[$path] !== $hash) {
                $changed[] = $path;
            }
        }

        return $changed;
    }

    /**
     * Трёхсторонняя сверка: база (ядро прошлой синхронизации) — общий предок,
     * «менялось в ядре» и «менялось у нас» считаются от него независимо.
     * Файл без предка, который у нас отличается от ядра, уходит в ручное
     * слияние: откуда он взялся, неизвестно, а затереть его нельзя.
     *
     * @return array{
     *     version_local: string, version_core: string,
     *     safe: array<int, string>, manual: array<int, string>, removed: array<int, string>,
     *     broken: array<string, array<int, string>>
     * }
     */
    public function diff(string $corePath): array
    {
        $baseline = $this->storedBaseline();
        $local    = $this->checksums($this->root);
        $core     = $this->checksums($corePath);

        $safe   = [];
        $manual = [];

        foreach ($core as $path => $hash) {
            $ancestor = $baseline[$path] ?? null;

            if (!isset($local[$path])) {
                if ($ancestor === null) {
                    $safe[] = $path; // новый файл ядра
                } elseif ($ancestor !== $hash) {
                    $manual[] = $path; // проект файл удалил, а ядро его с тех пор поменяло
                }

                continue;
            }

            if ($local[$path] === $hash || $ancestor === $hash) {
                continue; // совпадает с ядром или ядро файл не трогало — свой патч остаётся
            }

            if ($ancestor === $local[$path]) {
                $safe[] = $path;
            } else {
                $manual[] = $path;
            }
        }

        // Только то, что было в ядре, — свои файлы в framework/ ядро не удаляло
        $removed = [];

        foreach ($local as $path => $hash) {
            if (!isset($core[$path]) && isset($baseline[$path])) {
                $removed[] = $path;
            }
        }

        return [
            'version_local' => $this->localVersion(),
            'version_core'  => $this->readVersion($corePath),
            'safe'          => $safe,
            'manual'        => $manual,
            'removed'       => $removed,
            'broken'        => $this->brokenReferences($corePath),
        ];
    }

    /**
     * @param array<int, string> $paths относительные, из diff()['safe']
     *
     * @return array<int, string> реально скопированные
     */
    public function copyFiles(string $corePath, array $paths): array
    {
        $copied = [];

        foreach ($paths as $relative) {
            $source = rtrim($corePath, '/') . '/' . $relative;
            $target = $this->root . '/' . $relative;

            if (!is_file($source)) {
                continue;
            }

            $dir = dirname($target);

            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }

            $content = str_replace("\r\n", "\n", (string) file_get_contents($source));
            file_put_contents($target, $content);

            $copied[] = $relative;
        }

        return $copied;
    }

    /**
     * Ссылки в своём коде (app/, config/, routes/, resources/views/) на
     * классы ядра, которые в новом ядре либо исчезли целиком, либо потеряли
     * использованную константу/статический метод. Эвристика по тексту, не по
     * AST: не ловит вызов через интерфейс, не различает статический член от
     * обычного (`Класс::метод()` вместо `(new Класс())->метод()` — второе не
     * отслеживается вовсе, класса без единого `::`-обращения только по `new`
     * или подсказке типа в списке параметров хватает для находки «файл пропал
     * целиком», но не для «конкретный член пропал»). Не находит всё, но ловит
     * ровно то, что не поймать простым «диффом файлов»: скрытую поломку в
     * своём коде.
     *
     * @return array<string, array<int, string>> "Класс::член" (или просто
     *     "Класс", если файла не стало целиком) => список "файл:строка"
     */
    public function brokenReferences(string $corePath): array
    {
        $broken = [];

        foreach ($this->symbolUsages() as $fqcn => $usages) {
            $file = rtrim($corePath, '/') . '/' . $this->classToPath($fqcn);

            if (!is_file($file)) {
                $locations = array_merge(...array_values($usages));
                $broken[$fqcn] = $locations;

                continue;
            }

            foreach ($usages as $member => $locations) {
                // '' — обращение к самому классу без ::член; 'class' — магическая
                // ::class, а не настоящий член, определять её негде и незачем
                if ($member === '' || $member === 'class' || $this->definesMember($corePath, $fqcn, $member)) {
                    continue;
                }

                $broken[$fqcn . '::' . $member] = $locations;
            }
        }

        ksort($broken);

        return $broken;
    }

    /**
     * Ищет член в самом классе, а не найдя — в родителе: Active Record модели
     * почти всегда зовут find()/query() не у себя, а у общего Model, и без
     * подъёма по extends всё это ложно выглядело бы «пропавшим».
     */
    private function definesMember(string $corePath, string $fqcn, string $member, int $depth = 0): bool
    {
        if ($depth > 6 || !str_starts_with($fqcn, 'Rsgrinko\\Proton\\')) {
            return false;
        }

        $file = rtrim($corePath, '/') . '/' . $this->classToPath($fqcn);

        if (!is_file($file)) {
            return false;
        }

        $source = (string) file_get_contents($file);

        if (preg_match('/\bconst\s+' . preg_quote($member, '/') . '\b/', $source) === 1
            || preg_match('/\bfunction\s+' . preg_quote($member, '/') . '\s*\(/', $source) === 1) {
            return true;
        }

        if (preg_match('/\bclass\s+\w+\s+extends\s+([A-Za-z0-9_\\\\]+)/', $source, $m) !== 1) {
            return false;
        }

        $parent = $this->resolveClassName($source, $m[1]);

        return $parent !== null && $this->definesMember($corePath, $parent, $member, $depth + 1);
    }

    /**
     * Короткое имя класса из `extends` — в своё полное имя, тем же приёмом,
     * что и у клиентского кода: use в этом же файле, а без него — тот же
     * namespace, что у самого файла.
     */
    private function resolveClassName(string $source, string $name): ?string
    {
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }

        if (str_contains($name, '\\')) {
            return $name;
        }

        if (preg_match('/^use\s+([A-Za-z0-9_\\\\]+\\\\' . preg_quote($name, '/') . ')\s*;/m', $source, $m) === 1) {
            return $m[1];
        }

        if (preg_match('/^namespace\s+([A-Za-z0-9_\\\\]+);/m', $source, $m) === 1) {
            return $m[1] . '\\' . $name;
        }

        return null;
    }

    private function classToPath(string $fqcn): string
    {
        $inner = substr($fqcn, strlen('Rsgrinko\\Proton\\'));

        return 'framework/' . str_replace('\\', '/', $inner) . '.php';
    }

    /**
     * Разбирает свой код построчно: `use Rsgrinko\Proton\...;` даёт короткое
     * имя для класса в пределах файла, дальше `Короткое::член` резолвится
     * через эту карту. Полностью квалифицированное имя (`\Rsgrinko\Proton\...::член`)
     * учитывается и без use. Сам импорт тоже считается использованием под
     * ключом '' — иначе `new Logger()` или `function foo(User $user)` вообще
     * не попали бы в список, и «файл пропал целиком» осталось бы незамеченным
     * там, где к классу ни разу не обратились через ::.
     *
     * @return array<string, array<string, array<int, string>>> FQCN => член => ["файл:строка", ...]
     */
    private function symbolUsages(): array
    {
        $usages = [];

        foreach ($this->clientFiles() as $file) {
            $lines   = explode("\n", (string) file_get_contents($file));
            $imports = [];

            foreach ($lines as $number => $line) {
                if (preg_match('/^use\s+(Rsgrinko\\\\Proton\\\\[A-Za-z0-9_\\\\]+)(?:\s+as\s+([A-Za-z0-9_]+))?\s*;/', trim($line), $m) === 1) {
                    $fqcn  = $m[1];
                    $short = ($m[2] ?? '') !== '' ? $m[2] : substr($fqcn, strrpos($fqcn, '\\') + 1);
                    $imports[$short] = $fqcn;
                    $usages[$fqcn][''][] = $this->relative($file) . ':' . ($number + 1);
                }
            }

            foreach ($lines as $number => $line) {
                $location = $this->relative($file) . ':' . ($number + 1);

                // Полностью квалифицированное имя прямо в строке
                if (preg_match_all('/Rsgrinko\\\\Proton\\\\([A-Za-z0-9_\\\\]+)::([A-Za-z_][A-Za-z0-9_]*)/', $line, $matches, PREG_SET_ORDER) > 0) {
                    foreach ($matches as $match) {
                        $fqcn = 'Rsgrinko\\Proton\\' . $match[1];
                        $usages[$fqcn][$match[2]][] = $location;
                    }
                }

                // Короткое имя через use
                if (preg_match_all('/\b([A-Z][A-Za-z0-9_]*)::([A-Za-z_][A-Za-z0-9_]*)/', $line, $matches, PREG_SET_ORDER) > 0) {
                    foreach ($matches as $match) {
                        if (!isset($imports[$match[1]])) {
                            continue;
                        }

                        $usages[$imports[$match[1]]][$match[2]][] = $location;
                    }
                }
            }
        }

        return $usages;
    }

    /** @return array<int, string> */
    private function clientFiles(): array
    {
        $files = [];

        foreach (self::CLIENT_DIRS as $dir) {
            $base = $this->root . '/' . $dir;

            if (!is_dir($base)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }

    private function relative(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $root = str_replace('\\', '/', $this->root);

        return ltrim(str_replace($root, '', $path), '/');
    }

    /**
     * Версия ядра прямо из репозитория — обычный текстовый файл, без похода
     * в API и без авторизации.
     */
    public function remoteVersion(string $repo, string $branch): string
    {
        $response = (new HttpClient())->get($this->rawUrl($repo, $branch, self::VERSION_FILE));

        return $response['status'] === 200 ? trim($response['body']) : '';
    }

    public function remoteChangelog(string $repo, string $branch): string
    {
        $response = (new HttpClient())->get($this->rawUrl($repo, $branch, 'docs/CHANGELOG.md'));

        return $response['status'] === 200 ? $response['body'] : '';
    }

    /**
     * Записи журнала новее заданной версии, самая свежая сверху — ровно то,
     * что стоит прочитать между «на чём я сижу» и «что сейчас в ядре».
     */
    public static function changelogSince(string $changelog, string $sinceVersion): string
    {
        $out     = [];
        $capture = false;

        foreach (explode("\n", $changelog) as $line) {
            if (preg_match('/^##\s+(\d+\.\d+\.\d+)/', $line, $m) === 1) {
                $capture = version_compare($m[1], $sinceVersion) > 0;
            }

            if ($capture) {
                $out[] = $line;
            }
        }

        return trim(implode("\n", $out));
    }

    /**
     * Архив ветки одним запросом вместо `git clone` — распаковывается
     * встроенным PharData, ничего доустанавливать не нужно. Каталог нужно
     * убрать самостоятельно через cleanupRemote() после использования.
     */
    public function downloadRemote(string $repo, string $branch): string
    {
        $url      = $this->archiveUrl($repo, $branch);
        $response = (new HttpClient(30))->get($url);

        if ($response['status'] !== 200 || $response['body'] === '') {
            throw new ProtonException('Не удалось скачать архив ядра (код ' . $response['status'] . '): ' . $url);
        }

        // gzip разжимаем сами: PharData на .tar.gz пишет промежуточный файл в
        // sys_temp_dir, и если того каталога нет (частая история на Windows),
        // падает с «unable to create temporary file». Голый .tar читается на месте.
        $tar = @gzdecode($response['body']);

        if ($tar === false) {
            throw new ProtonException('Архив ядра не разжимается как gzip: ' . $url);
        }

        $dir = $this->root . '/var/tmp/core-sync-' . bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        $archive = $dir . '/core.tar';
        file_put_contents($archive, $tar);

        try {
            (new \PharData($archive))->extractTo($dir);
        } catch (\Throwable $e) {
            $this->removeTree($dir);

            throw new ProtonException('Архив ядра не распаковался: ' . $e->getMessage());
        }

        unlink($archive);

        // Архив распаковывается в подкаталог вида <repo>-<ветка>/; рядом
        // может лежать служебный pax_global_header, поэтому ищем по содержимому
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $entry) {
            if (is_dir($dir . '/' . $entry . '/framework')) {
                return $dir . '/' . $entry;
            }
        }

        $this->removeTree($dir);

        throw new ProtonException('Архив ядра распаковался, но framework/ внутри не нашёлся');
    }

    /**
     * Убирает временный каталог, оставшийся после downloadRemote().
     */
    public function cleanupRemote(string $extractedPath): void
    {
        // extractedPath — это .../core-sync-XXXX/<repo>-<ветка>, чистим на
        // уровень выше, весь временный каталог целиком
        $tmp = dirname($extractedPath);

        if (!str_contains($tmp, '/var/tmp/core-sync-') || !is_dir($tmp)) {
            return;
        }

        $this->removeTree($tmp);
    }

    private function removeTree(string $dir): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($dir);
    }

    private function rawUrl(string $repo, string $branch, string $path): string
    {
        $repo = rtrim($repo, '/');

        if (str_contains($repo, 'github.com')) {
            $ownerRepo = trim((string) parse_url($repo, PHP_URL_PATH), '/');

            return "https://raw.githubusercontent.com/{$ownerRepo}/{$branch}/{$path}";
        }

        // Gitea и совместимые: те же данные лежат по /raw/branch/<ветка>/<путь>
        return "{$repo}/raw/branch/{$branch}/{$path}";
    }

    /**
     * GitHub на github.com/.../archive/... отвечает 302 на codeload, а
     * HttpClient по редиректам нарочно не ходит — поэтому сразу codeload.
     */
    public function archiveUrl(string $repo, string $branch): string
    {
        $repo = rtrim($repo, '/');

        if (str_contains($repo, 'github.com')) {
            $ownerRepo = trim((string) parse_url($repo, PHP_URL_PATH), '/');

            return "https://codeload.github.com/{$ownerRepo}/tar.gz/refs/heads/{$branch}";
        }

        return "{$repo}/archive/{$branch}.tar.gz";
    }
}
