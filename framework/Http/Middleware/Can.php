<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Http\Middleware;

use Rsgrinko\Proton\Auth\Auth;
use Rsgrinko\Proton\Http\Request;
use Rsgrinko\Proton\Http\Response;
use Rsgrinko\Proton\View\View;

/**
 * Проверка права: can:users.manage. Несколько прав через черту — «хватит любого»:
 * can:notes.view|notes.manage.
 *
 * Ставится прослойкой у группы маршрутов, а не проверкой в контроллере: так
 * забыть её можно только вместе со всей группой.
 */
final class Can
{
    public function __invoke(Request $request, callable $next, string $permission = ''): Response
    {
        $viewer = Auth::viewer();

        $permissions = array_values(array_filter(explode('|', $permission)));

        if ($permissions === [] || $viewer->canAny($permissions)) {
            return $next($request);
        }

        if ($request->wantsJson()) {
            return Response::error('Недостаточно прав', 403);
        }

        return Response::html(View::render('errors/403', [
            'message' => 'Этот раздел вам не доступен.',
        ], 'Нет доступа'), 403);
    }
}
