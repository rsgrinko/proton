<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Http\Middleware;

use Rsgrinko\Proton\Auth\Auth;
use Rsgrinko\Proton\Http\Request;
use Rsgrinko\Proton\Http\Response;
use Rsgrinko\Proton\Install\Installer;
use Rsgrinko\Proton\View\View;

/**
 * Только для не вошедших: форма входа и регистрация. Вошедшего уводим на
 * главную — показывать ему форму входа незачем.
 */
final class Guest
{
    public function __invoke(Request $request, callable $next): Response
    {
        if (!Installer::installed()) {
            return Response::redirect(View::route('install'));
        }

        if (Auth::check()) {
            return Response::redirect(View::route('home'));
        }

        return $next($request);
    }
}
