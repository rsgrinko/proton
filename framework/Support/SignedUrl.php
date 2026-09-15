<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Support;

use Rsgrinko\Proton\Http\Request;
use Rsgrinko\Proton\Http\Router;

/**
 * Ссылка, которую нельзя подделать и которая сама протухает.
 *
 *     $url = SignedUrl::to('notes.file', ['id' => 5], 3600);   // на час
 *     $url = SignedUrl::absolute('report.download', ['id' => 7], 86400);
 *
 * Нужна там, где человеку надо что-то отдать без входа: файл из письма,
 * готовую выгрузку, подтверждение действия. Подпись считается от пути и всех
 * параметров ключом приложения (APP_KEY), поэтому подобрать её нельзя, а
 * поменять хоть один параметр — значит сломать.
 *
 * Проверяет ссылку прослойка `signed`. Срок жизни хранится прямо в адресе:
 * своей таблицы у таких ссылок нет, и отзывать по одной их нельзя — смена
 * APP_KEY обнуляет разом все.
 */
final class SignedUrl
{
    /** Имена служебных параметров в адресе */
    public const EXPIRES   = 'expires';
    public const SIGNATURE = 'signature';

    /**
     * Подписанный путь. Срок в секундах; 0 — без срока (ссылка живёт, пока
     * не сменится ключ приложения).
     *
     * @param array<string, mixed> $params
     */
    public static function to(string $route, array $params = [], int $lifetime = 3600): string
    {
        $params = self::withExpiry($params, $lifetime);

        return self::signUrl(Router::url($route, $params));
    }

    /**
     * То же с доменом — для писем: путь без домена там не кликается.
     *
     * @param array<string, mixed> $params
     */
    public static function absolute(string $route, array $params = [], int $lifetime = 3600): string
    {
        $params = self::withExpiry($params, $lifetime);

        return self::signUrl(Router::absolute($route, $params));
    }

    /**
     * Цела ли подпись и не вышел ли срок.
     */
    public static function valid(Request $request): bool
    {
        $query     = $request->query;
        $signature = (string) ($query[self::SIGNATURE] ?? '');

        if ($signature === '') {
            return false;
        }

        unset($query[self::SIGNATURE]);

        $expires = (int) ($query[self::EXPIRES] ?? 0);

        if ($expires > 0 && $expires < time()) {
            return false;
        }

        return Str::secureEquals(self::sign(self::canonical($request->path, $query)), $signature);
    }

    /**
     * Дописывает подпись к готовому адресу.
     *
     * Подписываем ровно то, что увидит проверяющий: путь (в нём уже сидят
     * параметры маршрута вроде {id}) и query-строку. Считать подпись от
     * исходного массива параметров нельзя — часть из них ушла в путь.
     */
    private static function signUrl(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $url . (str_contains($url, '?') ? '&' : '?')
            . self::SIGNATURE . '=' . self::sign(self::canonical($path, $query));
    }

    /**
     * Строка, которую подписываем: путь и параметры в одном порядке всегда.
     *
     * @param array<string, mixed> $params
     */
    private static function canonical(string $path, array $params): string
    {
        $values = [];

        foreach ($params as $key => $value) {
            if ($key === self::SIGNATURE) {
                continue;
            }

            $values[(string) $key] = is_scalar($value) ? (string) $value : '';
        }

        // Порядок параметров в адресе произвольный, а подпись должна сходиться
        ksort($values);

        return rtrim($path, '/') . '|' . http_build_query($values);
    }

    private static function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, self::secret());
    }

    /**
     * Ключ подписи — тот же, что у кук и шифрования: своего заводить незачем,
     * а его смена разом обнуляет все выданные ссылки.
     */
    private static function secret(): string
    {
        $key = (string) Config::get('app.key', '');

        if ($key === '') {
            throw new ProtonException('Не задан APP_KEY: подписывать ссылки нечем');
        }

        return $key;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private static function withExpiry(array $params, int $lifetime): array
    {
        if ($lifetime > 0) {
            $params[self::EXPIRES] = time() + $lifetime;
        }

        return $params;
    }
}
