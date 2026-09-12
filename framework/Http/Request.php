<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Http;

use Rsgrinko\Proton\Files\UploadedFile;
use Rsgrinko\Proton\Support\ClientIp;

/**
 * Входящий HTTP-запрос в удобном виде.
 *
 * Собирается из суперглобальных один раз в точке входа; всё дальше по коду
 * работает с этим объектом — в том числе тесты, которые собирают запрос руками.
 */
final class Request
{
    public string $method = 'GET';

    public string $path = '/';

    /** @var array<string, mixed> */
    public array $query = [];

    /** @var array<string, string> Заголовки, имена в нижнем регистре */
    public array $headers = [];

    public string $rawBody = '';

    /** @var array<string, mixed> Разобранное тело: JSON или обычная форма */
    public array $body = [];

    /** @var array<string, string> */
    public array $cookies = [];

    /** @var array<string, UploadedFile> Присланные файлы */
    public array $files = [];

    /** @var array<string, mixed> Что положили прослойки: пользователь, область видимости */
    public array $attributes = [];

    /**
     * Запрос из суперглобальных переменных.
     */
    public static function fromGlobals(): self
    {
        $request = new self();

        $request->method  = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $request->path    = self::normalizePath((string) ($_SERVER['REQUEST_URI'] ?? '/'));
        $request->query   = $_GET;
        $request->headers = self::readHeaders();
        $request->cookies = array_map('strval', $_COOKIE);
        $request->rawBody = (string) file_get_contents('php://input');
        $request->body    = self::parseBody($request);
        $request->files   = UploadedFile::fromGlobals($_FILES);

        // Метод формы: браузер умеет только GET и POST, а маршруты бывают
        // и PUT, и DELETE — форма кладёт настоящий метод скрытым полем
        $override = strtoupper((string) ($request->body['_method'] ?? ''));

        if ($request->method === 'POST' && in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
            $request->method = $override;
        }

        return $request;
    }

    /**
     * Запрос для тестов и внутренних вызовов.
     *
     * @param array<string, mixed>  $body
     * @param array<string, mixed>  $query
     * @param array<string, string> $headers
     */
    public static function create(string $method, string $path, array $body = [], array $query = [], array $headers = []): self
    {
        $request = new self();

        $request->method  = strtoupper($method);
        $request->path    = self::normalizePath($path);
        $request->query   = $query;
        $request->body    = $body;
        $request->headers = array_change_key_case($headers, CASE_LOWER);
        $request->rawBody = $body === [] ? '' : (string) json_encode($body, JSON_UNESCAPED_UNICODE);

        return $request;
    }

    public function header(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }

    public function cookie(string $name, string $default = ''): string
    {
        return $this->cookies[$name] ?? $default;
    }

    /**
     * Значение из query-строки.
     */
    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /**
     * Значение из тела запроса.
     */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    /**
     * Тело плюс query-строка — то, что обычно и нужно форме.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return array_merge($this->query, $this->body);
    }

    /**
     * Только перечисленные поля.
     *
     * @param array<int, string> $keys
     *
     * @return array<string, mixed>
     */
    public function only(array $keys): array
    {
        $all    = $this->all();
        $result = [];

        foreach ($keys as $key) {
            if (array_key_exists($key, $all)) {
                $result[$key] = $all[$key];
            }
        }

        return $result;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    /**
     * Галочка формы: браузер шлёт «on» или не шлёт ничего.
     */
    public function boolean(string $key): bool
    {
        return in_array((string) $this->input($key, $this->query($key, '')), ['1', 'on', 'true', 'yes'], true);
    }

    public function integer(string $key, int $default = 0): int
    {
        $value = $this->input($key, $this->query($key, null));

        return $value === null || $value === '' ? $default : (int) $value;
    }

    public function text(string $key, string $default = ''): string
    {
        $value = $this->input($key, $this->query($key, null));

        return $value === null ? $default : trim((string) $value);
    }

    public function file(string $key): ?UploadedFile
    {
        return $this->files[$key] ?? null;
    }

    /**
     * Данные, положенные прослойкой.
     */
    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function setAttribute(string $key, mixed $value): self
    {
        $this->attributes[$key] = $value;

        return $this;
    }

    public function isMethod(string $method): bool
    {
        return $this->method === strtoupper($method);
    }

    /**
     * Меняет ли запрос состояние — по этому признаку работает проверка токена форм.
     */
    public function isWriting(): bool
    {
        return in_array($this->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }

    /**
     * Ждёт ли клиент JSON: запрос от fetch или к API.
     */
    public function wantsJson(): bool
    {
        return str_contains($this->header('accept'), 'application/json')
            || str_contains($this->header('content-type'), 'application/json')
            || strtolower($this->header('x-requested-with')) === 'xmlhttprequest';
    }

    /**
     * API-ключ из заголовка Authorization: Bearer <ключ>, понимаем и X-Api-Key.
     */
    public function bearerToken(): string
    {
        $auth = $this->header('authorization');

        if (stripos($auth, 'bearer ') === 0) {
            return trim(substr($auth, 7));
        }

        return trim($this->header('x-api-key'));
    }

    /**
     * Почему разобранное тело пустое. Сломанный JSON стоит отличать от честно
     * пустого запроса: иначе клиент с опечаткой в кавычке ищет ошибку не там.
     */
    public function bodyError(): ?string
    {
        if ($this->body !== [] || trim($this->rawBody) === '') {
            return null;
        }

        if (!str_contains($this->header('content-type'), 'application/json')) {
            return null;
        }

        $decoded = json_decode($this->rawBody, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return 'Не удалось разобрать JSON: ' . json_last_error_msg();
        }

        return is_array($decoded) ? null : 'Тело запроса должно быть объектом JSON';
    }

    /**
     * Адрес клиента: за прокси берётся из X-Forwarded-For, но только если запрос
     * пришёл от доверенного прокси — иначе заголовок подделает кто угодно.
     */
    public function ip(): string
    {
        $ip = ClientIp::resolve(
            (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            $this->header('x-forwarded-for')
        );

        return $ip === '' ? 'unknown' : $ip;
    }

    public function userAgent(): string
    {
        return trim($this->header('user-agent'));
    }

    /**
     * Полный адрес с query-строкой — для ссылки «вернуться назад».
     */
    public function fullPath(): string
    {
        return $this->query === [] ? $this->path : $this->path . '?' . http_build_query($this->query);
    }

    /**
     * @return array<string, string>
     */
    private static function readHeaders(): array
    {
        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (str_starts_with((string) $key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr((string) $key, 5)))] = (string) $value;
            }
        }

        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }

        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $headers['content-length'] = (string) $_SERVER['CONTENT_LENGTH'];
        }

        return $headers;
    }

    /**
     * @return array<string, mixed>
     */
    private static function parseBody(self $request): array
    {
        $type = $request->header('content-type');

        if (str_contains($type, 'application/json')) {
            $decoded = json_decode($request->rawBody, true);

            return is_array($decoded) ? $decoded : [];
        }

        if (in_array($request->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) && $_POST !== []) {
            return $_POST;
        }

        if ($request->rawBody !== '' && str_contains($type, 'application/x-www-form-urlencoded')) {
            parse_str($request->rawBody, $parsed);

            return $parsed;
        }

        return [];
    }

    /**
     * Путь без хвостового слэша — так же его нормализует маршрут.
     */
    private static function normalizePath(string $uri): string
    {
        $path = rtrim((string) (parse_url($uri, PHP_URL_PATH) ?: '/'), '/');

        return $path === '' ? '/' : $path;
    }
}
