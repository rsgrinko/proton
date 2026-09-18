<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Http;

/**
 * Ответ приложения: тело, код, заголовки и куки.
 *
 * Куки лежат отдельным списком, а не в карте заголовков: их в одном ответе
 * бывает несколько (сессия, «запомнить меня», токен форм), и в карте вторая
 * затёрла бы первую.
 */
final class Response
{
    private int $status;

    private string $body;

    /** @var array<string, string> */
    private array $headers;

    /** @var array<int, string> Готовые значения Set-Cookie */
    private array $cookies = [];

    /**
     * @param array<string, string> $headers
     */
    public function __construct(string $body = '', int $status = 200, array $headers = [])
    {
        $this->body    = $body;
        $this->status  = $status;
        $this->headers = $headers;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function json(array $data, int $status = 200): self
    {
        return new self(
            (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            $status,
            ['Content-Type' => 'application/json; charset=utf-8']
        );
    }

    /**
     * Ошибка в едином формате: {"error": {...}}.
     *
     * @param array<string, mixed> $details
     */
    public static function error(string $message, int $status = 400, array $details = []): self
    {
        $error = ['message' => $message, 'status' => $status];

        if ($details !== []) {
            $error['details'] = $details;
        }

        return self::json(['error' => $error], $status);
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($html, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function text(string $text, int $status = 200): self
    {
        return new self($text, $status, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    /**
     * Перенаправление — после любого действия, меняющего данные: перезагрузка
     * страницы не должна отправлять форму второй раз.
     */
    public static function redirect(string $location, int $status = 302): self
    {
        return new self('', $status, ['Location' => $location]);
    }

    /**
     * Файл на скачивание.
     */
    public static function download(string $content, string $fileName, string $type = 'application/octet-stream'): self
    {
        return new self($content, 200, [
            'Content-Type'        => $type,
            'Content-Disposition' => 'attachment; filename="' . str_replace('"', '', $fileName) . '"',
        ]);
    }

    /**
     * Отдача файла с диска — картинки и вложения из хранилища.
     */
    public static function file(string $path, string $type = 'application/octet-stream', bool $download = false): self
    {
        $response = new self((string) file_get_contents($path), 200, ['Content-Type' => $type]);

        if ($download) {
            $response->withHeader('Content-Disposition', 'attachment; filename="' . basename($path) . '"');
        }

        return $response;
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;

        return $this;
    }

    public function withStatus(int $status): self
    {
        $this->status = $status;

        return $this;
    }

    /**
     * Меняет готовое тело — нужно панели отладки: она подставляется в конце
     * Kernel::handle(), когда тело уже собрано, чтобы попасть в него зная
     * итоговые отметки времени.
     */
    public function withBody(string $body): self
    {
        $this->body = $body;

        return $this;
    }

    /**
     * Готовая строка Set-Cookie.
     */
    public function withCookie(string $cookie): self
    {
        $this->cookies[] = $cookie;

        return $this;
    }

    /**
     * @return array<int, string>
     */
    public function cookies(): array
    {
        return $this->cookies;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public function header(string $name): string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return '';
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    /**
     * Отдаёт ответ клиенту.
     */
    public function send(): void
    {
        http_response_code($this->status);

        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }

        // Куки добавляем, а не заменяем: PHP уже положил в Set-Cookie куку сессии,
        // и header() со значением по умолчанию выбросил бы её — сессия не держалась бы
        foreach ($this->cookies as $cookie) {
            header('Set-Cookie: ' . $cookie, false);
        }

        echo $this->body;
    }
}
