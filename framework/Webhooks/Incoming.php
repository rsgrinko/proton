<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Webhooks;

use Rsgrinko\Proton\Http\Request;
use Rsgrinko\Proton\Models\IncomingHook;
use Rsgrinko\Proton\Support\Logger;
use Rsgrinko\Proton\Support\Str;
use Throwable;

/**
 * Входящие вебхуки: чужая система стучится к нам.
 *
 * Обратная сторона `Webhooks` — там мы рассылаем свои события, здесь принимаем
 * чужие. Источник объявляется в `config/incoming.php`:
 *
 *     Incoming::register('billing', 'Биллинг', 'BILLING_HOOK_SECRET', static function (array $payload): void {
 *         Order::pay((int) $payload['order_id']);
 *     });
 *
 * Подпись проверяется тем же способом, каким подписываем свои посылки: HMAC-SHA256
 * от «время.тело» секретом источника в заголовке X-Proton-Signature. Источник
 * без секрета принимается как есть — так бывает у систем, которые подписывать
 * не умеют, и это осознанный выбор того, кто его завёл.
 *
 * Каждая посылка попадает в журнал: разбираться с «мы отправили, а вы не приняли»
 * иначе нечем.
 */
final class Incoming
{
    /** Сколько секунд посылка считается свежей */
    private const WINDOW = 300;

    /** @var array<string, array{label: string, secret: string, handler: callable}>|null */
    private static ?array $sources = null;

    /**
     * @param string   $secret  имя переменной окружения с секретом; пусто — без подписи
     * @param callable(array<string, mixed>, Request): void $handler
     */
    public static function register(string $key, string $label, string $secret, callable $handler): void
    {
        self::boot();

        self::$sources[$key] = ['label' => $label, 'secret' => $secret, 'handler' => $handler];
    }

    /**
     * @return array<string, array{label: string, secret: string, handler: callable}>
     */
    public static function sources(): array
    {
        self::boot();

        return self::$sources ?? [];
    }

    /**
     * @return array{label: string, secret: string, handler: callable}|null
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

        // Тело берём у запроса, а не из php://input: ядро его уже прочитало,
        // и второй раз поток отдаст пустоту
        $body    = $request->rawBody;
        $payload = $request->all();

        if ($body !== '' && $payload === []) {
            $payload = (array) json_decode($body, true);
        }

        $event = (string) ($payload['event'] ?? $payload['type'] ?? '');

        if (!self::verify($source['secret'], $request, $body)) {
            IncomingHook::write($key, $event, $payload, IncomingHook::REJECTED, 'подпись не сошлась');

            return IncomingHook::REJECTED;
        }

        try {
            ($source['handler'])($payload, $request);

            IncomingHook::write($key, $event, $payload, IncomingHook::DONE);

            return IncomingHook::DONE;
        } catch (Throwable $e) {
            // Обработчик упал — посылку принимаем, но помечаем: чужая система
            // не должна слать её бесконечно, а разобраться мы сможем по журналу
            IncomingHook::write($key, $event, $payload, IncomingHook::FAILED, $e->getMessage());

            (new Logger('webhooks'))->error('Входящий вебхук упал', [
                'source' => $key,
                'error'  => $e->getMessage(),
            ]);

            return IncomingHook::FAILED;
        }
    }

    /**
     * Цела ли подпись. Источник без секрета проверять нечем — принимаем.
     */
    private static function verify(string $secretName, Request $request, string $body): bool
    {
        if ($secretName === '') {
            return true;
        }

        $secret = (string) (getenv($secretName) ?: '');

        if ($secret === '') {
            return false;
        }

        $signature = $request->header('x-proton-signature');
        $timestamp = (int) $request->header('x-proton-timestamp');

        if ($signature === '' || $timestamp <= 0) {
            return false;
        }

        // Старую посылку не принимаем: подслушанную её иначе можно повторить
        if (abs(time() - $timestamp) > self::WINDOW) {
            return false;
        }

        return Str::secureEquals(hash_hmac('sha256', $timestamp . '.' . $body, $secret), $signature);
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
