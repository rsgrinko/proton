<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Http\Middleware;

use Rsgrinko\Proton\Auth\Auth;
use Rsgrinko\Proton\Http\Request;
use Rsgrinko\Proton\Http\Response;
use Rsgrinko\Proton\Install\Installer;
use Rsgrinko\Proton\View\View;

/**
 * Пускает только вошедших. Заодно кладёт в атрибуты запроса пользователя,
 * viewer и область видимости — роутер подставит их в контроллер по именам
 * аргументов, и лезть в сессию из контроллера не придётся.
 */
final class Authenticate
{
    public function __invoke(Request $request, callable $next): Response
    {
        // Приложение ещё не установлено — сначала установщик, а не форма входа
        if (!Installer::installed()) {
            return Response::redirect(View::route('install'));
        }

        $user = Auth::user();

        if ($user === null) {
            if ($request->wantsJson()) {
                return Response::error('Нужно войти', 401);
            }

            // Куда вернуть после входа: человек шёл по ссылке, а не на главную
            View::stash('intended', $request->fullPath());

            return Response::redirect(View::route('login'));
        }

        $viewer = Auth::viewer();

        $request->setAttribute('user', $user)
            ->setAttribute('viewer', $viewer)
            ->setAttribute('scope', $viewer->scope());

        return $next($request);
    }
}
