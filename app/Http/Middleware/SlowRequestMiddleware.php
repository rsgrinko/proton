<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Rsgrinko\Proton\Http\Request;
use Rsgrinko\Proton\Http\Response;
use Rsgrinko\Proton\Support\Logger;

/**
 * Пример своей прослойки — образец на замену, если понадобится что-то
 * подобное: свой заголовок, доп. проверка на раздел, замер времени.
 *
 * Пишет в лог запросы раздела, которые отвечали дольше порога. Порог —
 * аргумент через двоеточие, как у throttle:120,60: slowlog:300 значит
 * «дольше 300 мс».
 *
 * Ядро трогать не нужно: своя прослойка регистрируется по имени прямо в файле
 * маршрутов, тем же $router, что получает Kernel, — см. `$router->middleware(...)`
 * в начале routes/web.php и docs/ROUTING.md, раздел «Прослойки».
 */
final class SlowRequestMiddleware
{
    private const DEFAULT_THRESHOLD_MS = 500;

    public function __invoke(Request $request, callable $next, string $thresholdMs = ''): Response
    {
        $threshold = $thresholdMs === '' ? self::DEFAULT_THRESHOLD_MS : max(1, (int) $thresholdMs);
        $started   = microtime(true);

        $response = $next($request);

        $elapsed = (microtime(true) - $started) * 1000;

        if ($elapsed >= $threshold) {
            (new Logger('slow-requests'))->warning('Медленный запрос раздела', [
                'method' => $request->method,
                'path'   => $request->path,
                'ms'     => round($elapsed, 1),
            ]);
        }

        return $response;
    }
}
