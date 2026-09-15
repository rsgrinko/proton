<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Http\Middleware;

use Rsgrinko\Proton\Http\Request;
use Rsgrinko\Proton\Http\Response;
use Rsgrinko\Proton\Support\SignedUrl;
use Rsgrinko\Proton\View\View;

/**
 * Пускает только по целой и не протухшей подписи: signed.
 *
 * Ставится там, где адрес открывают без входа — файл из письма, готовая
 * выгрузка. Вместе с `auth` смысла не имеет: если человек и так вошёл,
 * подпись ничего не добавляет.
 */
final class Signed
{
    public function __invoke(Request $request, callable $next): Response
    {
        if (SignedUrl::valid($request)) {
            return $next($request);
        }

        if ($request->wantsJson()) {
            return Response::error('Ссылка недействительна или устарела', 403);
        }

        return Response::html(View::render('errors/403', [
            'message' => 'Ссылка недействительна или устарела. Попросите её заново.',
        ], 'Ссылка не работает'), 403);
    }
}
