<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Access;

use Rsgrinko\Proton\Support\ProtonException;

/**
 * Правило на запись не пустило. Ядро превращает это в 403: страницу для
 * браузера, JSON для API.
 *
 * Отдельное исключение, а не возврат Response из контроллера: проверку ставят
 * первой строкой действия, и она должна прерывать работу, а не требовать
 * помнить про return.
 */
final class AccessDenied extends ProtonException
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(string $message = 'Нет доступа', array $context = [])
    {
        parent::__construct($message, $context, 403);
    }
}
