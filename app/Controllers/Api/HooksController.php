<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use Rsgrinko\Proton\Http\Request;
use Rsgrinko\Proton\Http\Response;
use Rsgrinko\Proton\Models\IncomingHook;
use Rsgrinko\Proton\Webhooks\Incoming;

/**
 * Приём чужих вебхуков: POST или GET /api/v1/hooks/{источник}.
 *
 * Ключ API здесь не нужен — чужая система его не знает; вместо него токен,
 * который проверяет `Incoming`. Отвечаем быстро и коротко: отправитель ждёт
 * ответа и повторит посылку, если мы задумаемся.
 */
final class HooksController extends ApiController
{
    public function receive(Request $request, string $source): Response
    {
        $status = Incoming::receive($source, $request);

        return match ($status) {
            IncomingHook::DONE     => $this->data(['status' => 'принято']),
            IncomingHook::REJECTED => Response::error('Токен не подошёл', 401),
            IncomingHook::UNKNOWN  => Response::error('Неизвестный источник', 404),
            // Обработчик упал — посылку мы приняли, повторять её не нужно
            default                => $this->data(['status' => 'принято с ошибкой'], 202),
        };
    }
}
