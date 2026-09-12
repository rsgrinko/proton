<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Http;

use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;
use Rsgrinko\Proton\Support\Container;
use Rsgrinko\Proton\Support\ProtonException;

/**
 * Роутер. Маршруты описываются в файлах routes/:
 *
 *     $router->group(['prefix' => '/admin', 'middleware' => ['auth', 'can:users.manage']], function (Router $router) {
 *         $router->get('/users', [UsersController::class, 'index'])->name('admin.users');
 *         $router->get('/users/{id:\d+}', [UsersController::class, 'show'])->name('admin.users.show');
 *     });
 *
 * Обработчик — замыкание или пара [класс, метод]: класс создаётся, только если
 * маршрут совпал, и получает зависимости из контейнера. Аргументы метода
 * подставляются по именам: Request — сам запрос, {id} — из адреса (с приведением
 * к типу аргумента), остальное — из атрибутов, которые кладут прослойки.
 */
final class Router
{
    /** @var array<int, Route> */
    private array $routes = [];

    /** @var array<string, callable> Прослойки по именам */
    private array $middleware = [];

    /** @var array{prefix: string, middleware: array<int, string>} Текущая группа */
    private array $group = ['prefix' => '', 'middleware' => []];

    /** @var array<string, Route> Именованные маршруты — общие для всех роутеров */
    private static array $named = [];

    /**
     * Подключает файл маршрутов. Файл возвращает функцию, принимающую роутер.
     */
    public function load(string $file): self
    {
        if (!is_file($file)) {
            throw new ProtonException('Файл маршрутов не найден: ' . $file);
        }

        $definition = require $file;

        if (!is_callable($definition)) {
            throw new ProtonException('Файл маршрутов должен возвращать функцию: ' . $file);
        }

        $definition($this);

        return $this;
    }

    /**
     * Регистрирует прослойку под именем, которым её потом зовут маршруты.
     */
    public function middleware(string $name, callable $handler): self
    {
        $this->middleware[$name] = $handler;

        return $this;
    }

    /**
     * Общий префикс и прослойки для группы. Группы вкладываются друг в друга.
     *
     * @param array{prefix?: string, middleware?: string|array<int, string>} $options
     */
    public function group(array $options, callable $routes): self
    {
        $previous = $this->group;

        $this->group = [
            'prefix'     => $previous['prefix'] . rtrim((string) ($options['prefix'] ?? ''), '/'),
            'middleware' => array_merge($previous['middleware'], (array) ($options['middleware'] ?? [])),
        ];

        $routes($this);

        $this->group = $previous;

        return $this;
    }

    /**
     * @param callable|array{0: class-string, 1: string} $handler
     */
    public function get(string $pattern, mixed $handler): Route
    {
        return $this->add(['GET'], $pattern, $handler);
    }

    /**
     * @param callable|array{0: class-string, 1: string} $handler
     */
    public function post(string $pattern, mixed $handler): Route
    {
        return $this->add(['POST'], $pattern, $handler);
    }

    /**
     * @param callable|array{0: class-string, 1: string} $handler
     */
    public function put(string $pattern, mixed $handler): Route
    {
        return $this->add(['PUT'], $pattern, $handler);
    }

    /**
     * @param callable|array{0: class-string, 1: string} $handler
     */
    public function patch(string $pattern, mixed $handler): Route
    {
        return $this->add(['PATCH'], $pattern, $handler);
    }

    /**
     * @param callable|array{0: class-string, 1: string} $handler
     */
    public function delete(string $pattern, mixed $handler): Route
    {
        return $this->add(['DELETE'], $pattern, $handler);
    }

    /**
     * Один адрес на несколько методов — например, форма: GET показать, POST принять.
     *
     * @param array<int, string>                         $methods
     * @param callable|array{0: class-string, 1: string} $handler
     */
    public function match(array $methods, string $pattern, mixed $handler): Route
    {
        return $this->add($methods, $pattern, $handler);
    }

    /**
     * @param array<int, string>                         $methods
     * @param callable|array{0: class-string, 1: string} $handler
     */
    public function add(array $methods, string $pattern, mixed $handler): Route
    {
        $route = new Route($methods, $this->group['prefix'] . $pattern, $handler, $this->group['middleware']);

        $this->routes[] = $route;

        return $route;
    }

    /**
     * Ищет подходящий маршрут и отдаёт ответ.
     * Путь не нашёлся — 404, нашёлся, но не с этим методом — 405.
     */
    public function dispatch(Request $request): Response
    {
        $pathMatched = false;

        foreach ($this->routes as $route) {
            $params = $route->match($request->path);

            if ($params === null) {
                continue;
            }

            $pathMatched = true;

            if (!$route->allows($request->method)) {
                continue;
            }

            return $this->run($route, $request, $params);
        }

        if ($pathMatched) {
            return Response::error('Метод ' . $request->method . ' для этого адреса не поддерживается', 405);
        }

        return Response::error('Адрес не найден: ' . $request->path, 404);
    }

    /**
     * Все зарегистрированные маршруты — нужно команде route:list и проверкам.
     *
     * @return array<int, Route>
     */
    public function routes(): array
    {
        return $this->routes;
    }

    /**
     * Адрес именованного маршрута.
     *
     * @param array<string, mixed> $params
     */
    public static function url(string $name, array $params = []): string
    {
        if (!isset(self::$named[$name])) {
            throw new ProtonException('Неизвестный маршрут: ' . $name);
        }

        return self::$named[$name]->url($params);
    }

    /**
     * Есть ли такой маршрут — вьюхе иногда нужно спросить, не падая.
     */
    public static function has(string $name): bool
    {
        return isset(self::$named[$name]);
    }

    /**
     * Запоминает именованный маршрут — зовётся из Route::name().
     */
    public static function remember(Route $route): void
    {
        if ($route->name !== null) {
            self::$named[$route->name] = $route;
        }
    }

    /**
     * Прогоняет запрос через прослойки маршрута и вызывает обработчик.
     *
     * @param array<string, string> $params
     */
    private function run(Route $route, Request $request, array $params): Response
    {
        $next = fn (Request $request): Response => $this->call($route->handler, $request, $params);

        // Идём с конца, чтобы первая прослойка в списке оказалась внешней
        foreach (array_reverse($route->middleware) as $name) {
            // Прослойке можно передать аргумент через двоеточие: can:users.manage
            [$name, $argument] = array_pad(explode(':', $name, 2), 2, '');

            if (!isset($this->middleware[$name])) {
                throw new ProtonException('Прослойка «' . $name . '» не зарегистрирована');
            }

            $handler = $this->middleware[$name];
            $inner   = $next;
            $next    = static fn (Request $request): Response => $argument === ''
                ? $handler($request, $inner)
                : $handler($request, $inner, $argument);
        }

        return $next($request);
    }

    /**
     * Вызывает обработчик маршрута, подставляя ему аргументы.
     *
     * @param callable|array{0: class-string, 1: string} $handler
     * @param array<string, string>                      $params
     */
    private function call(mixed $handler, Request $request, array $params): Response
    {
        if (is_array($handler) && is_string($handler[0])) {
            $controller = Container::instance()->make($handler[0]);
            $method     = new ReflectionMethod($controller, $handler[1]);

            /** @var Response $response */
            $response = $method->invokeArgs($controller, $this->arguments($method, $request, $params));

            return $response;
        }

        if (!is_callable($handler)) {
            throw new ProtonException('Обработчик маршрута должен быть функцией или парой [класс, метод]');
        }

        /** @var Response $response */
        $response = $handler($request, $params);

        return $response;
    }

    /**
     * Аргументы метода: запрос, параметры адреса и атрибуты запроса.
     *
     * @param array<string, string> $params
     *
     * @return array<int, mixed>
     */
    private function arguments(ReflectionFunctionAbstract $method, Request $request, array $params): array
    {
        $arguments = [];

        foreach ($method->getParameters() as $parameter) {
            $name = $parameter->getName();
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType && $type->getName() === Request::class) {
                $arguments[] = $request;

                continue;
            }

            if (array_key_exists($name, $params)) {
                $arguments[] = $this->cast($params[$name], $type instanceof ReflectionNamedType ? $type->getName() : 'string');

                continue;
            }

            if (array_key_exists($name, $request->attributes)) {
                $arguments[] = $request->attributes[$name];

                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();

                continue;
            }

            if ($parameter->allowsNull()) {
                $arguments[] = null;

                continue;
            }

            throw new ProtonException('Для обработчика маршрута нечего подставить в параметр «' . $name . '»');
        }

        return $arguments;
    }

    private function cast(string $value, string $type): mixed
    {
        return match ($type) {
            'int'   => (int) $value,
            'float' => (float) $value,
            'bool'  => in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true),
            default => $value,
        };
    }
}
