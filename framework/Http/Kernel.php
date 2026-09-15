<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Http;

use Rsgrinko\Proton\Auth\Auth;
use Rsgrinko\Proton\Auth\Csrf;
use Rsgrinko\Proton\Database\Model\RecordNotFound;
use Rsgrinko\Proton\Http\Middleware\ApiKey;
use Rsgrinko\Proton\Http\Middleware\Authenticate;
use Rsgrinko\Proton\Http\Middleware\Can;
use Rsgrinko\Proton\Http\Middleware\Guest;
use Rsgrinko\Proton\Http\Middleware\Install;
use Rsgrinko\Proton\Http\Middleware\Throttle;
use Rsgrinko\Proton\Http\Middleware\VerifyCsrf;
use Rsgrinko\Proton\Support\Config;
use Rsgrinko\Proton\Support\Logger;
use Rsgrinko\Proton\Support\Metrics;
use Rsgrinko\Proton\Support\ProtonException;
use Rsgrinko\Proton\Support\RequestId;
use Rsgrinko\Proton\Support\Settings;
use Rsgrinko\Proton\Support\ValidationException;
use Rsgrinko\Proton\View\View;
use Throwable;

/**
 * Ядро HTTP: собирает роутер, прогоняет запрос и превращает любое исключение
 * в понятный ответ.
 *
 * Файлы маршрутов перечислены в config/config.php (routes). Ответ на ошибку
 * зависит от того, чего ждёт клиент: браузеру — страница, API — JSON.
 */
final class Kernel
{
    private ?Router $router = null;

    private Logger $logger;

    public function __construct()
    {
        $this->logger = new Logger('http');
    }

    public function handle(Request $request): Response
    {
        $started = microtime(true);

        // Сквозная цепочка: клиент прислал свою или заводим новую
        RequestId::set($request->header(RequestId::HEADER));

        // Значения из панели ложатся поверх .env до того, как их кто-то прочитает
        Settings::apply();

        try {
            $response = $this->finish($this->router()->dispatch($request));
        } catch (RecordNotFound $e) {
            $response = $this->finish($this->notFound($request, $e));
        } catch (ValidationException $e) {
            $response = $this->finish($this->invalid($request, $e));
        } catch (Throwable $e) {
            $response = $this->finish($this->crashed($request, $e));
        }

        // Счётчики пишутся после ответа и молча: сорванный подсчёт не повод
        // портить страницу
        try {
            Metrics::track((microtime(true) - $started) * 1000, $response->status());
        } catch (Throwable $e) {
            $this->logger->warning('Не удалось записать показатели', ['error' => $e->getMessage()]);
        }

        return $response;
    }

    /**
     * Роутер собирается при первом запросе, а не в конструкторе: прослойки
     * ходят в базу, и её падение должно попасть в обработчик ошибок.
     */
    public function router(): Router
    {
        if ($this->router !== null) {
            return $this->router;
        }

        $router = new Router();

        $router->middleware('csrf', new VerifyCsrf())
            ->middleware('auth', new Authenticate())
            ->middleware('guest', new Guest())
            ->middleware('can', new Can())
            ->middleware('api', new ApiKey())
            ->middleware('throttle', new Throttle())
            ->middleware('install', new Install());

        foreach ((array) Config::get('routes', ['routes/web.php']) as $file) {
            $router->load(APP_ROOT . '/' . ltrim((string) $file, '/'));
        }

        return $this->router = $router;
    }

    /**
     * Куки навешиваются здесь: их ставит Auth (долгая «запомнить меня») и Csrf
     * (токен форм), а заголовки есть только у ответа. Одно место на все ветки,
     * включая страницу ошибки, — про куку нельзя забыть.
     */
    private function finish(Response $response): Response
    {
        return Csrf::applyCookie(Auth::applyCookies($response))
            ->withHeader(RequestId::HEADER, RequestId::current());
    }

    private function notFound(Request $request, RecordNotFound $e): Response
    {
        if ($request->wantsJson()) {
            return Response::error($e->getMessage(), 404);
        }

        // Раздел сказал, куда возвращать человека, — вернём со словами
        if ($e->route() !== '') {
            View::flash($e->getMessage(), 'error');

            return Response::redirect(View::route($e->route()));
        }

        return Response::html(View::render('errors/404', ['message' => $e->getMessage()], 'Не найдено'), 404);
    }

    private function invalid(Request $request, ValidationException $e): Response
    {
        if ($request->wantsJson()) {
            return Response::error('Данные не прошли проверку', 422, ['errors' => $e->errors()]);
        }

        // Форму возвращаем человеку заполненной: перебивать всё заново незачем
        View::stash('old', $request->all());
        View::stash('errors', $e->errors());
        View::flash($e->first(), 'error');

        return Response::redirect($request->header('referer') !== '' ? $request->header('referer') : $request->fullPath());
    }

    private function crashed(Request $request, Throwable $e): Response
    {
        // По этому коду ошибку находят в логе: текст исключения пользователю не показываем
        $code = strtoupper(bin2hex(random_bytes(3)));

        $this->logger->error('Ошибка запроса', [
            'code'  => $code,
            'path'  => $request->path,
            'error' => $e->getMessage(),
            'file'  => $e->getFile() . ':' . $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]);

        $debug = (bool) Config::get('app.debug', false);

        $details = $debug
            ? $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ')'
            : 'Внутренняя ошибка. Подробности в логе, код ошибки: ' . $code;

        if ($request->wantsJson()) {
            return Response::error($details, $e instanceof ProtonException && $e->getCode() >= 400 ? (int) $e->getCode() : 500);
        }

        return Response::html(View::render('errors/500', [
            'message' => $details,
            'trace'   => $debug ? $e->getTraceAsString() : '',
        ], 'Ошибка'), 500);
    }
}
