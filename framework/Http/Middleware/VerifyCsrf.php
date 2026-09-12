<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Http\Middleware;

use Rsgrinko\Proton\Auth\Csrf;
use Rsgrinko\Proton\Http\Request;
use Rsgrinko\Proton\Http\Response;
use Rsgrinko\Proton\View\View;

/**
 * Сверяет токен формы при любом изменяющем запросе.
 *
 * Вешается на группу веб-маршрутов целиком: забыть её на одном контроллере
 * куда проще, чем на всей группе. API это не касается — там ключ в заголовке,
 * а не кука, и подделать запрос с чужого сайта нельзя.
 */
final class VerifyCsrf
{
    public function __invoke(Request $request, callable $next): Response
    {
        if (!$request->isWriting()) {
            return $next($request);
        }

        $token = (string) $request->input(Csrf::FIELD, $request->header(Csrf::HEADER));

        if (Csrf::check($token)) {
            return $next($request);
        }

        if ($request->wantsJson()) {
            return Response::error('Форма устарела, обновите страницу', 403);
        }

        View::flash('Форма устарела. Обновите страницу и попробуйте ещё раз', 'error');

        return Response::html(View::render('errors/403', [
            'message' => 'Не сходится токен формы. Обычно это значит, что страница была открыта слишком долго.',
        ], 'Форма устарела'), 403);
    }
}
