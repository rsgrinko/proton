<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Support;

/**
 * Чем кончился импорт: сколько строк загрузилось, сколько нет и почему.
 * Показывается человеку целиком — иначе он не поймёт, что править в файле.
 */
final class ImportReport
{
    /** Сколько ошибок показываем: остальные в том же духе */
    private const SHOW_ERRORS = 20;

    /**
     * @param array<int, string> $errors
     */
    public function __construct(
        public readonly int $loaded,
        public readonly int $failed,
        public readonly array $errors = [],
    ) {
    }

    public function ok(): bool
    {
        return $this->failed === 0 && $this->loaded > 0;
    }

    /**
     * Первые ошибки — списком для страницы.
     *
     * @return array<int, string>
     */
    public function firstErrors(): array
    {
        return array_slice($this->errors, 0, self::SHOW_ERRORS);
    }

    /**
     * Сколько ошибок не показали.
     */
    public function restErrors(): int
    {
        return max(0, count($this->errors) - self::SHOW_ERRORS);
    }

    /**
     * Строка для сообщения и журнала.
     */
    public function summary(): string
    {
        if ($this->loaded === 0 && $this->failed === 0) {
            return 'Из файла ничего не загружено';
        }

        $text = 'Загружено строк: ' . $this->loaded;

        return $this->failed > 0 ? $text . ', с ошибками: ' . $this->failed : $text;
    }
}
