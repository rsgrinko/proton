<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Http\Middleware;

use Rsgrinko\Proton\Http\Request;
use Rsgrinko\Proton\Http\Response;
use Rsgrinko\Proton\Install\Installer;
use Rsgrinko\Proton\View\View;

/**
 * Веб-установщик открыт, только пока приложение не установлено.
 *
 * Иначе мастером воспользовался бы любой прохожий: он пишет .env и заводит
 * администратора.
 */
final class Install
{
    public function __invoke(Request $request, callable $next): Response
    {
        if (Installer::installed()) {
            return Response::redirect(View::route('home'));
        }

        return $next($request);
    }
}
