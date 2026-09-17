<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Http;

use Rsgrinko\Proton\Access\AccessDenied;
use Rsgrinko\Proton\Access\Permission;
use Rsgrinko\Proton\Auth\Auth;
use Rsgrinko\Proton\Auth\Csrf;
use Rsgrinko\Proton\Database\Model\RecordNotFound;
use Rsgrinko\Proton\Models\BlockedIp;
use Rsgrinko\Proton\Models\SecurityEvent;
use Rsgrinko\Proton\Http\Middleware\ApiKey;
use Rsgrinko\Proton\Http\Middleware\Authenticate;
use Rsgrinko\Proton\Http\Middleware\Can;
use Rsgrinko\Proton\Http\Middleware\Guest;
use Rsgrinko\Proton\Http\Middleware\Install;
use Rsgrinko\Proton\Http\Middleware\Signed;
use Rsgrinko\Proton\Http\Middleware\Throttle;
use Rsgrinko\Proton\Http\Middleware\VerifyCsrf;
use Rsgrinko\Proton\Support\Config;
use Rsgrinko\Proton\Support\Logger;
use Rsgrinko\Proton\Support\Maintenance;
use Rsgrinko\Proton\Support\Metrics;
use Rsgrinko\Proton\Queue\HitWorker;
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
            $response = $this->finish(
                $this->maintenance($request) ?? $this->blocked($request) ?? $this->router()->dispatch($request)
            );
        } catch (AccessDenied $e) {
            $response = $this->finish($this->denied($request, $e));
        } catch (RecordNotFound $e) {
            $response = $this->finish($this->notFound($request, $e));
        } catch (ValidationException $e) {
            $response = $this->finish($this->invalid($request, $e));
        } catch (ProtonException $e) {
            $response = $this->finish($this->httpError($request, $e));
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

        // Подстраховка без отдельного демона — выключено по умолчанию,
        // см. Queue\HitWorker
        HitWorker::run();

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
            ->middleware('install', new Install())
            ->middleware('signed', new Signed());

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

    /**
     * Режим обслуживания разворачиваем до маршрутов и до блокировки по IP:
     * своему администратору (право system.manage) он не мешает — иначе
     * править что-то на живую под собственным же режимом обслуживания
     * пришлось бы через отдельный allow-список. Пустой ответ — режим выключен.
     */
    private function maintenance(Request $request): ?Response
    {
        if (!Maintenance::active()) {
            return null;
        }

        if (Maintenance::allows($request->ip()) || Auth::viewer()->can(Permission::SYSTEM_MANAGE)) {
            return null;
        }

        $payload = Maintenance::payload();
        $message = $payload['message'] !== '' ? $payload['message'] : 'Идут технические работы, зайдите чуть позже.';

        $response = $request->wantsJson()
            ? Response::error($message, 503)
            : Response::html(View::render('errors/503', ['message' => $message], 'Технические работы'), 503);

        return $payload['retry'] > 0 ? $response->withHeader('Retry-After', (string) $payload['retry']) : $response;
    }

    /**
     * Закрытый адрес разворачиваем до маршрутов: заблокированному нечего делать
     * ни на форме входа, ни в API. Пустой ответ означает «адрес не закрыт».
     */
    private function blocked(Request $request): ?Response
    {
        if (!(bool) Config::get('security.blocklist', true) || !BlockedIp::blocked($request->ip())) {
            return null;
        }

        SecurityEvent::record(SecurityEvent::IP_BLOCKED, '', $request->path, $request->userAgent());

        if ($request->wantsJson()) {
            return Response::error('Доступ с этого адреса закрыт', 403);
        }

        return Response::html(View::render('errors/403', [
            'message' => 'Доступ с этого адреса закрыт. Если это ошибка, обратитесь к администратору.',
        ], 'Доступ закрыт'), 403);
    }

    /**
     * Правило на запись не пустило.
     */
    private function denied(Request $request, AccessDenied $e): Response
    {
        if ($request->wantsJson()) {
            return Response::error($e->getMessage(), 403);
        }

        return Response::html(View::render('errors/403', ['message' => $e->getMessage()], 'Нет доступа'), 403);
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

    /**
     * Ошибка с кодом ответа: неизвестный адрес, неподходящий метод. Человеку
     * нужна страница, а не сырой JSON, поэтому вид выбирается здесь, а не там,
     * где ошибку бросили. Всё, что 500 и выше, идёт как обычная поломка — с
     * записью в лог и кодом для поиска.
     */
    private function httpError(Request $request, ProtonException $e): Response
    {
        $status = (int) $e->getCode();

        if ($status < 400 || $status > 499) {
            return $this->crashed($request, $e);
        }

        if ($request->wantsJson()) {
            return Response::error($e->getMessage(), $status);
        }

        $title = $status === 405 ? 'Метод не поддерживается' : 'Не найдено';

        return Response::html(View::render('errors/404', ['message' => $e->getMessage()], $title), $status);
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
