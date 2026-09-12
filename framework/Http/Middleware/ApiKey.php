<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Http\Middleware;

use Rsgrinko\Proton\Access\Viewer;
use Rsgrinko\Proton\Http\Request;
use Rsgrinko\Proton\Http\Response;
use Rsgrinko\Proton\Models\ApiToken;
use Rsgrinko\Proton\Models\User;
use Rsgrinko\Proton\Support\IpAllowlist;

/**
 * Ключ API: Authorization: Bearer ptn_xxx_yyy.
 *
 * Ключ — такой же вход в данные, как и пароль, поэтому область видимости
 * действует и здесь: в атрибуты кладётся владелец ключа, его viewer и scope,
 * и контроллер API работает ровно с теми записями, что и его хозяин в панели.
 */
final class ApiKey
{
    public function __invoke(Request $request, callable $next): Response
    {
        $key = $request->bearerToken();

        if ($key === '') {
            return Response::error('Не передан ключ доступа: Authorization: Bearer <ключ>', 401);
        }

        $token = ApiToken::match($key);

        if ($token === null) {
            return Response::error('Ключ доступа не найден или отключён', 401);
        }

        $allowed = trim((string) $token->raw('allowed_ips'));

        // Пустой список — ограничения нет; заполненный проверяем целиком,
        // включая сети CIDR
        if ($allowed !== '' && !IpAllowlist::allows($allowed, $request->ip())) {
            return Response::error('Этот ключ нельзя использовать с адреса ' . $request->ip(), 403);
        }

        $user = User::find((int) $token->raw('user_id'));

        if ($user === null || !$user->isActive()) {
            return Response::error('Владелец ключа отключён', 403);
        }

        $token->markUsed($request->ip());

        $viewer = Viewer::fromUser($user);

        $request->setAttribute('token', $token)
            ->setAttribute('user', $user)
            ->setAttribute('viewer', $viewer)
            ->setAttribute('scope', $viewer->scope());

        return $next($request);
    }
}
