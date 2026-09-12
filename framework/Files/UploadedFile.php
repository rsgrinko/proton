<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Files;

/**
 * Файл, присланный формой. Обёртка над строкой из $_FILES: сам по себе этот
 * массив неудобен и легко читается неверно (особенно при нескольких файлах).
 */
final class UploadedFile
{
    private string $name;

    private string $tmpPath;

    private int $size;

    private int $error;

    public function __construct(string $name, string $tmpPath, int $size, int $error)
    {
        $this->name    = $name;
        $this->tmpPath = $tmpPath;
        $this->size    = $size;
        $this->error   = $error;
    }

    /**
     * Разбирает $_FILES. Несколько файлов в одном поле приходят как список.
     *
     * @param array<string, mixed> $files
     *
     * @return array<string, self>
     */
    public static function fromGlobals(array $files): array
    {
        $result = [];

        foreach ($files as $field => $file) {
            if (!is_array($file) || !isset($file['name'])) {
                continue;
            }

            if (is_array($file['name'])) {
                foreach (array_keys($file['name']) as $index) {
                    $result[$field . '.' . $index] = new self(
                        (string) $file['name'][$index],
                        (string) $file['tmp_name'][$index],
                        (int) $file['size'][$index],
                        (int) $file['error'][$index]
                    );
                }

                continue;
            }

            $result[(string) $field] = new self(
                (string) $file['name'],
                (string) $file['tmp_name'],
                (int) $file['size'],
                (int) $file['error']
            );
        }

        return $result;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function tmpPath(): string
    {
        return $this->tmpPath;
    }

    public function size(): int
    {
        return $this->size;
    }

    public function extension(): string
    {
        return strtolower((string) pathinfo($this->name, PATHINFO_EXTENSION));
    }

    /**
     * Тип по содержимому, а не по имени: расширение пишет тот же, кто прислал файл.
     */
    public function mime(): string
    {
        if (!is_file($this->tmpPath) || !function_exists('finfo_open')) {
            return 'application/octet-stream';
        }

        $info = finfo_open(FILEINFO_MIME_TYPE);

        if ($info === false) {
            return 'application/octet-stream';
        }

        $mime = finfo_file($info, $this->tmpPath);

        finfo_close($info);

        return $mime === false ? 'application/octet-stream' : $mime;
    }

    public function uploaded(): bool
    {
        return $this->error === UPLOAD_ERR_OK && $this->tmpPath !== '' && is_file($this->tmpPath);
    }

    /**
     * Что пошло не так при загрузке — понятным текстом.
     */
    public function error(): ?string
    {
        return match ($this->error) {
            UPLOAD_ERR_OK         => null,
            UPLOAD_ERR_INI_SIZE,
            UPLOAD_ERR_FORM_SIZE  => 'Файл больше разрешённого размера',
            UPLOAD_ERR_PARTIAL    => 'Файл передан не полностью',
            UPLOAD_ERR_NO_FILE    => 'Файл не выбран',
            UPLOAD_ERR_NO_TMP_DIR => 'На сервере нет каталога для временных файлов',
            UPLOAD_ERR_CANT_WRITE => 'Не удалось записать файл на диск',
            UPLOAD_ERR_EXTENSION  => 'Загрузку остановило расширение PHP',
            default               => 'Файл не загрузился',
        };
    }
}
