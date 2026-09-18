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

        // Запись — сразу после заголовка файла, свежее выше старого
        if (preg_match('/^# .+\n\n/', $body, $m) === 1) {
            $body = substr_replace($body, $entry, strlen($m[0]), 0);
        } else {
            $body = $entry . $body;
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
     * Фиксирует нынешнее состояние framework/ как доверенное — разово, сразу
     * после того, как проект завели от ядра, или один раз сейчас задним числом.
     */
    public function saveBaseline(): void
    {
        $sums = $this->checksums($this->root);

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
     * Обновляет доверенное состояние только для перечисленных файлов — а не
     * пересчитывает всё дерево заново. Файл из списка «требует ручного
     * слияния» не копировался и своей старой доверенной суммы лишиться не
     * должен: пересчёт всего дерева тихо принял бы его текущий (патченный)
     * вид за новую базу, и следующий диф перестал бы видеть в нём патч —
     * следующее обновление ядра тогда затёрло бы его как будто «безопасный» файл.
     *
     * @param array<int, string> $paths
     */
    public function markSynced(array $paths): void
    {
        $baseline = $this->storedBaseline();
        $current  = $this->checksums($this->root);

        foreach ($paths as $path) {
            if (isset($current[$path])) {
                $baseline[$path] = $current[$path];
            }
        }

        ksort($baseline);

        file_put_contents(
            $this->root . '/' . self::BASELINE_FILE,
            json_encode($baseline, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );
    }

    /**
     * Файлы framework/, изменившиеся с последней сохранённой базы, — без
     * похода к ядру вообще, чистая локальная проверка «ничего не поправили
     * в обход правила с последнего раза».
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
     * Три списка вместо одного диффа: «менялось у ядра» и «менялось у нас» —
     * два независимых вопроса, а не одна ось.
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
            if (!isset($local[$path])) {
                $safe[] = $path; // новый файл ядра — своего аналога нет, патчить нечего

                continue;
            }

            if ($local[$path] === $hash) {
                continue; // не изменилось
            }

            $patchedLocally = isset($baseline[$path]) && $baseline[$path] !== $local[$path];

            if ($patchedLocally) {
                $manual[] = $path;
            } else {
                $safe[] = $path;
            }
        }

        $removed = [];

        foreach ($local as $path => $hash) {
            if (!isset($core[$path])) {
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
        $response = (new HttpClient(30))->get($this->archiveUrl($repo, $branch));

        if ($response['status'] !== 200 || $response['body'] === '') {
            throw new ProtonException('Не удалось скачать архив ядра (код ' . $response['status'] . '): ' . $this->archiveUrl($repo, $branch));
        }

        $dir = $this->root . '/var/tmp/core-sync-' . bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        $archive = $dir . '/core.tar.gz';
        file_put_contents($archive, $response['body']);

        (new \PharData($archive))->extractTo($dir);
        unlink($archive);

        // Архив распаковывается в подкаталог вида <repo>-<ветка>/
        $entries   = array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
        $extracted = $entries[0] ?? '';

        if ($extracted === '' || !is_dir($dir . '/' . $extracted . '/framework')) {
            throw new ProtonException('Архив ядра распаковался, но framework/ внутри не нашёлся');
        }

        return $dir . '/' . $extracted;
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

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($tmp);
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

    private function archiveUrl(string $repo, string $branch): string
    {
        $repo = rtrim($repo, '/');

        return str_contains($repo, 'github.com')
            ? "{$repo}/archive/refs/heads/{$branch}.tar.gz"
            : "{$repo}/archive/{$branch}.tar.gz";
    }
}
