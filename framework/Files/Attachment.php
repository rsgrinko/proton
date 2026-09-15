<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Files;

use Rsgrinko\Proton\Database\Model\Model;
use Rsgrinko\Proton\Support\Str;

/**
 * Файл, приложенный к записи.
 *
 *     Attachment::attach($file, 'note', $note->id(), $viewer->id());
 *     Attachment::of('note', $note->id());            // все вложения записи
 *     Attachment::detachAll('note', $note->id());     // удалить вместе с файлами
 *
 * Сам файл лежит в хранилище вне public и отдаётся только кодом: присланный
 * «аватар.php» не должен стать скриптом на сайте. Запись в таблице — это ещё
 * и признак нужности: файл без записи считается сиротой и убирается командой.
 */
final class Attachment extends Model
{
    protected static string $table = 'attachments';

    protected array $casts = ['size' => 'int'];

    /** Какие типы показываем картинкой, а не значком */
    private const IMAGES = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    /**
     * Приложить файл к записи.
     */
    public static function attach(UploadedFile $file, string $entity, int|string $entityId, int $userId = 0): self
    {
        $stored = Storage::put($file, $entity);

        $attachment = new self();

        $attachment->forceFill([
            'entity'    => $entity,
            'entity_id' => (string) $entityId,
            'path'      => $stored['path'],
            'name'      => $stored['name'],
            'mime'      => $stored['mime'],
            'size'      => $stored['size'],
            'user_id'   => $userId > 0 ? $userId : null,
        ]);

        $attachment->save();

        return $attachment;
    }

    /**
     * Вложения записи, свежие сверху.
     *
     * @return array<int, self>
     */
    public static function of(string $entity, int|string $entityId): array
    {
        /** @var array<int, self> $found */
        $found = self::query()
            ->where('entity', $entity)
            ->where('entity_id', (string) $entityId)
            ->orderBy('id', 'desc')
            ->get();

        return $found;
    }

    /**
     * Убрать все вложения записи вместе с файлами: запись удалили — файлы ей
     * больше не нужны.
     */
    public static function detachAll(string $entity, int|string $entityId): int
    {
        $removed = 0;

        foreach (self::of($entity, $entityId) as $attachment) {
            $removed += $attachment->remove() ? 1 : 0;
        }

        return $removed;
    }

    /**
     * Файлы хранилища, на которые никто не ссылается.
     *
     * @return array<int, string> пути от корня хранилища
     */
    public static function orphans(): array
    {
        $known = [];

        foreach (self::query()->rows() as $row) {
            $known[(string) $row['path']] = true;
        }

        $orphans = [];
        $root    = rtrim(str_replace('\\', '/', Storage::root()), '/');

        foreach (self::walk($root) as $file) {
            $relative = ltrim(substr(str_replace('\\', '/', $file), strlen($root)), '/');

            if (!isset($known[$relative])) {
                $orphans[] = $relative;
            }
        }

        return $orphans;
    }

    /**
     * Удалить вложение вместе с файлом.
     */
    public function remove(): bool
    {
        Storage::delete((string) $this->raw('path'));

        return $this->delete();
    }

    /**
     * Картинка ли это — её показывают превью, остальное значком.
     */
    public function isImage(): bool
    {
        $extension = strtolower(pathinfo((string) $this->raw('name'), PATHINFO_EXTENSION));

        return in_array($extension, self::IMAGES, true);
    }

    /**
     * Размер для показа человеку.
     */
    public function readableSize(): string
    {
        return Str::bytes((int) $this->raw('size'));
    }

    /**
     * Обход хранилища. Каталоги плоские по годам и месяцам, поэтому рекурсия
     * неглубокая и не требует ничего сложнее.
     *
     * @return array<int, string>
     */
    private static function walk(string $dir): array
    {
        $files = [];

        foreach ((array) glob($dir . '/*') as $item) {
            $item = (string) $item;

            if (is_dir($item)) {
                $files = array_merge($files, self::walk($item));

                continue;
            }

            if (is_file($item)) {
                $files[] = $item;
            }
        }

        return $files;
    }
}
