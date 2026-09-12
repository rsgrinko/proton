<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Files;

use Rsgrinko\Proton\Support\Config;
use Rsgrinko\Proton\Support\ProtonException;
use Rsgrinko\Proton\Support\Str;

/**
 * Хранилище загруженных файлов.
 *
 * Файлы лежат вне public: отдаёт их контроллер, а не веб-сервер, — иначе
 * присланный «аватар.php» становится исполняемым скриптом на сайте. Имя на диске
 * своё, случайное; настоящее остаётся в базе рядом со ссылкой.
 *
 * Что разрешено принимать, задаётся настройкой files.allowed: расширение и тип
 * проверяются оба, потому что расширение пишет тот же, кто прислал файл.
 */
final class Storage
{
    /**
     * Кладёт присланный файл в хранилище.
     *
     * @return array{path: string, name: string, size: int, mime: string}
     */
    public static function put(UploadedFile $file, string $folder = ''): array
    {
        if (!$file->uploaded()) {
            throw new ProtonException($file->error() ?? 'Файл не загрузился');
        }

        $limit = (int) Config::get('files.max_size', 5 * 1024 * 1024);

        if ($limit > 0 && $file->size() > $limit) {
            throw new ProtonException('Файл больше разрешённых ' . Str::bytes($limit));
        }

        self::assertAllowed($file);

        $extension = $file->extension();
        $relative  = trim($folder, '/');
        $relative  = ($relative === '' ? '' : $relative . '/') . date('Y/m') . '/'
            . Str::random(24) . ($extension === '' ? '' : '.' . $extension);

        $target = self::root() . '/' . $relative;
        $dir    = dirname($target);

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new ProtonException('Не удалось создать каталог хранилища: ' . $dir);
        }

        // move_uploaded_file на настоящей загрузке, rename — в тестах и консоли,
        // где файл никто не «загружал»
        $moved = is_uploaded_file($file->tmpPath())
            ? move_uploaded_file($file->tmpPath(), $target)
            : rename($file->tmpPath(), $target);

        if (!$moved) {
            throw new ProtonException('Не удалось сохранить файл');
        }

        @chmod($target, 0644);

        return [
            'path' => $relative,
            'name' => $file->name(),
            'size' => (int) filesize($target),
            'mime' => $file->mime(),
        ];
    }

    /**
     * Полный путь к файлу хранилища. Выход за его пределы запрещён:
     * «../../.env» в имени не должен превращаться в чтение настроек.
     */
    public static function path(string $relative): string
    {
        $root = self::root();
        $full = $root . '/' . ltrim(str_replace('\\', '/', $relative), '/');

        $real     = realpath($full);
        $realRoot = realpath($root);

        if ($real === false || $realRoot === false || !str_starts_with($real, $realRoot)) {
            throw new ProtonException('Файл вне хранилища: ' . $relative);
        }

        return $real;
    }

    public static function exists(string $relative): bool
    {
        try {
            return is_file(self::path($relative));
        } catch (ProtonException) {
            return false;
        }
    }

    public static function read(string $relative): string
    {
        return (string) file_get_contents(self::path($relative));
    }

    public static function delete(string $relative): bool
    {
        try {
            return @unlink(self::path($relative));
        } catch (ProtonException) {
            return false;
        }
    }

    /**
     * Сколько всего занято хранилищем — показывает состояние сервиса.
     */
    public static function usedBytes(): int
    {
        $total = 0;
        $root  = self::root();

        if (!is_dir($root)) {
            return 0;
        }

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $total += $file->getSize();
            }
        }

        return $total;
    }

    public static function root(): string
    {
        $dir = (string) Config::get('paths.storage', APP_ROOT . '/var/storage');

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return rtrim(str_replace('\\', '/', $dir), '/');
    }

    /**
     * Разрешён ли такой файл. Проверяем и расширение, и тип по содержимому:
     * порознь каждую проверку легко обойти.
     */
    private static function assertAllowed(UploadedFile $file): void
    {
        /** @var array<int, string> $allowed */
        $allowed = (array) Config::get('files.allowed', []);

        if ($allowed === []) {
            return;
        }

        if (!in_array($file->extension(), array_map('strtolower', $allowed), true)) {
            throw new ProtonException('Такие файлы принимать нельзя: ' . ($file->extension() ?: 'без расширения'));
        }

        /** @var array<int, string> $mimes */
        $mimes = (array) Config::get('files.allowed_mime', []);

        if ($mimes !== [] && !in_array($file->mime(), $mimes, true)) {
            throw new ProtonException('Содержимое файла не совпадает с разрешёнными типами: ' . $file->mime());
        }
    }
}
