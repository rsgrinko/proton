<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Database\Model;

use Rsgrinko\Proton\Support\ProtonException;

/**
 * Записи нет. Ядро панели ловит это исключение и возвращает человека в список
 * с сообщением, API отвечает 404 — проверять «а вдруг null» в каждом контроллере
 * не нужно.
 */
final class RecordNotFound extends ProtonException
{
    /** Куда вернуть человека — имя маршрута списка */
    private string $route;

    public function __construct(string $message = 'Запись не найдена', string $route = '')
    {
        parent::__construct($message, [], 404);

        $this->route = $route;
    }

    public function route(): string
    {
        return $this->route;
    }
}
