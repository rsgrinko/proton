<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Support;

/**
 * Что получится, если загрузить файл: сколько записей заведётся, сколько
 * обновится и что отвалится. Ничего не пишет — это предпросмотр.
 */
final class ImportPlan
{
    public const CREATE = 'create';
    public const UPDATE = 'update';
    public const SKIP   = 'skip';

    /** @var array<string, string> Подписи для страницы */
    public const LABELS = [
        self::CREATE => 'заведём',
        self::UPDATE => 'обновим',
        self::SKIP   => 'пропустим',
    ];

    /**
     * @param array<int, array{line: int, verdict: string, data: array<string, mixed>, error: string}> $sample
     * @param array<int, string>                                                                       $errors
     */
    public function __construct(
        public readonly int $create,
        public readonly int $update,
        public readonly int $failed,
        public readonly array $sample = [],
        public readonly array $errors = [],
    ) {
    }

    /**
     * Есть ли что загружать.
     */
    public function any(): bool
    {
        return $this->create > 0 || $this->update > 0;
    }

    public function total(): int
    {
        return $this->create + $this->update + $this->failed;
    }

    /**
     * Строка для кнопки и сообщения.
     */
    public function summary(): string
    {
        if ($this->total() === 0) {
            return 'Загружать нечего';
        }

        $parts = [];

        if ($this->create > 0) {
            $parts[] = 'заведём ' . $this->create;
        }

        if ($this->update > 0) {
            $parts[] = 'обновим ' . $this->update;
        }

        if ($this->failed > 0) {
            $parts[] = 'пропустим ' . $this->failed;
        }

        return implode(', ', $parts);
    }

    public static function label(string $verdict): string
    {
        return self::LABELS[$verdict] ?? $verdict;
    }
}
