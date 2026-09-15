<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Webhooks;

use Rsgrinko\Proton\Http\Request;
use Rsgrinko\Proton\Models\IncomingHook;
use Rsgrinko\Proton\Support\Env;
use Rsgrinko\Proton\Support\Logger;
use Rsgrinko\Proton\Support\Str;
use Throwable;

/**
 * Входящие вебхуки: чужая система стучится к нам.
 *
 * Обратная сторона `Webhooks` — там мы рассылаем свои события, здесь принимаем
 * чужие. Источник объявляется в `config/incoming.php`:
 *
 *     Incoming::register('billing', 'Биллинг', 'BILLING_HOOK_TOKEN', static function (array $payload): void {
 *         Queue::push(MarkOrderPaidJob::class, ['order' => (int) $payload['id']]);
 *     });
 *
 * Проверка простая — токен. Отправитель присылает его заголовком
 * `X-Proton-Token`, через `Authorization: Bearer …` или параметром `token`:
 * как умеет. Сам токен лежит в .env (или в окружении процесса), в реестре только
 * её имя: чужому секрету в репозитории не место. Источник, объявленный без токена,
 * принимается как есть: так бывает у систем, которые не умеют и этого.
 *
 * Принимаются и POST с телом JSON, и GET с параметрами — обработчик в обоих
 * случаях получает одинаковый массив.
 *
 * Каждая посылка попадает в журнал: разбираться с «мы отправили, а вы не
 * приняли» иначе нечем.
 */
final class Incoming
{
    /** Заголовок с токеном */
    public const HEADER = 'x-proton-token';

    /** @var array<string, array{label: string, token: string, handler: callable}>|null */
    private static ?array $sources = null;

    /**
     * @param string $token имя переменной с токеном (.env или окружение); пусто — без проверки
     * @param callable(array<string, mixed>, Request): void $handler
     */
    public static function register(string $key, string $label, string $token, callable $handler): void
    {
        self::boot();

        self::$sources[$key] = ['label' => $label, 'token' => $token, 'handler' => $handler];
    }

    /**
     * @return array<string, array{label: string, token: string, handler: callable}>
     */
    public static function sources(): array
    {
        self::boot();

        return self::$sources ?? [];
    }

    /**
     * @return array{label: string, token: string, handler: callable}|null
     */
    public static function source(string $key): ?array
    {
        return self::sources()[$key] ?? null;
    }

    /**
     * Принять посылку. Возвращает состояние: принято, отклонено или сломалось.
     */
    public static function receive(string $key, Request $request): string
    {
        $source = self::source($key);

        if ($source === null) {
            // Записываем и это: по журналу видно, что кто-то стучится не туда —
            // например, источник переехал, а отправителю не сказали
            IncomingHook::write($key, '', [], IncomingHook::UNKNOWN, 'источник не объявлен');

            return IncomingHook::UNKNOWN;
        }

        $payload = $request->all();

        // Тело JSON ядро разбирает само, но чужие системы шлют по-разному —
        // на всякий случай разбираем и сырое тело
        if ($payload === [] && trim($request->rawBody) !== '') {
            $payload = (array) json_decode($request->rawBody, true);
        }

        $event = (string) ($payload['event'] ?? $payload['type'] ?? '');

        $refusal = self::refusal($source['token'], $request);

        if ($refusal !== null) {
            IncomingHook::write($key, $event, self::withoutToken($payload), IncomingHook::REJECTED, $refusal);

            return IncomingHook::REJECTED;
        }

        try {
            ($source['handler'])($payload, $request);

            IncomingHook::write($key, $event, self::withoutToken($payload), IncomingHook::DONE);

            return IncomingHook::DONE;
        } catch (Throwable $e) {
            // Обработчик упал — посылку принимаем, но помечаем: чужая система
            // не должна слать её бесконечно, а разобраться мы сможем по журналу
            IncomingHook::write($key, $event, self::withoutToken($payload), IncomingHook::FAILED, $e->getMessage());

            (new Logger('webhooks'))->error('Входящий вебхук упал', [
                'source' => $key,
                'error'  => $e->getMessage(),
            ]);

            return IncomingHook::FAILED;
        }
    }

    /**
     * Почему посылку не приняли. null — приняли, иначе текст для журнала.
     *
     * Причины разные, и различать их важно: «токен не подошёл» — вопрос
     * к отправителю, «переменная пуста» — к нам самим. Без этого своя
     * недонастройка выглядит как чужая ошибка, и ищут её не там.
     */
    private static function refusal(string $variable, Request $request): ?string
    {
        // Источник объявлен без токена — проверять нечем
        if ($variable === '') {
            return null;
        }

        // Env смотрит и в окружение процесса, и в .env: токен кладут и туда, и туда
        $expected = Env::string($variable, '');

        if ($expected === '') {
            return 'источник не настроен: переменная ' . $variable . ' пуста';
        }

        $given = self::token($request);

        if ($given === '') {
            return 'токена в запросе нет';
        }

        return Str::secureEquals($expected, $given) ? null : 'токен не подошёл';
    }

    /**
     * Токен из запроса: заголовком, в Authorization или параметром.
     */
    private static function token(Request $request): string
    {
        $header = trim($request->header(self::HEADER));

        if ($header !== '') {
            return $header;
        }

        $bearer = trim($request->bearerToken());

        if ($bearer !== '') {
            return $bearer;
        }

        // У GET-посылки параметры лежат в адресе, а не в теле
        return trim((string) $request->input('token', $request->query('token', '')));
    }

    /**
     * Токен не должен осесть в журнале: там его увидит каждый, кто смотрит
     * входящие посылки.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private static function withoutToken(array $payload): array
    {
        unset($payload['token']);

        return $payload;
    }

    /**
     * Забыть источники — нужно тестам.
     */
    public static function reset(): void
    {
        self::$sources = null;
    }

    private static function boot(): void
    {
        if (self::$sources !== null) {
            return;
        }

        self::$sources = [];

        $file = APP_ROOT . '/config/incoming.php';

        if (is_file($file)) {
            require $file;
        }
    }
}
