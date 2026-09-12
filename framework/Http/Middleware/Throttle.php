<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Http\Middleware;

use Rsgrinko\Proton\Http\Request;
use Rsgrinko\Proton\Http\Response;
use Rsgrinko\Proton\Models\ApiToken;
use Rsgrinko\Proton\RateLimit\RateLimiter;

/**
 * Ограничение частоты: throttle:60,60 — не больше 60 запросов за 60 секунд.
 *
 * Считаем по ключу API, если он есть, иначе по адресу: иначе один клиент за
 * общим NAT перекрыл бы кислород всем соседям.
 */
final class Throttle
{
    public function __invoke(Request $request, callable $next, string $options = '60,60'): Response
    {
        [$max, $seconds] = array_pad(explode(',', $options), 2, '60');

        $max     = max(1, (int) $max);
        $seconds = max(1, (int) $seconds);

        $limiter = new RateLimiter();
        $key     = 'throttle:' . $this->subject($request) . ':' . $seconds;

        if (!$limiter->attempt($key, $max, $seconds)) {
            $retry = $limiter->retryAfter($key);

            return Response::error('Слишком много запросов, попробуйте позже', 429, ['retry_after' => $retry])
                ->withHeader('Retry-After', (string) max(1, $retry));
        }

        return $next($request);
    }

    private function subject(Request $request): string
    {
        $token = $request->attribute('token');

        if ($token instanceof ApiToken) {
            return 'token:' . $token->id();
        }

        return 'ip:' . $request->ip();
    }
}
