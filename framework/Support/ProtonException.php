<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Support;

use RuntimeException;
use Throwable;

/**
 * Базовое исключение приложения. Всё своё наследуется от него — по нему ядро
 * отличает понятную ошибку от чужой.
 */
class ProtonException extends RuntimeException
{
    /** @var array<string, mixed> Дополнительный контекст для логов и ответов API */
    private array $context;

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(string $message, array $context = [], int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);

        $this->context = $context;
    }

    /**
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }
}
