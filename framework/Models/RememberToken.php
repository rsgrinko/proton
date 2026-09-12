<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Models;

use Rsgrinko\Proton\Database\Connection;
use Rsgrinko\Proton\Database\Model\Model;
use Rsgrinko\Proton\Support\Str;

/**
 * Галка «запомнить меня»: долгая кука вида selector:validator.
 *
 * Пароля здесь нет, и войти по одной лишь базе нельзя: validator хранится хешем.
 * При каждом входе по куке validator меняется, а selector остаётся — поэтому
 * приход со старым validator означает, что кукой пользуется кто-то ещё, и токен
 * гасится целиком: настоящему владельцу придётся войти паролем, и он это заметит.
 *
 * Прежний validator живёт ещё минуту (previous_hash, rotated_at): страницу
 * браузер открывает не одним запросом, соседние приходят с той же старой кукой,
 * и без этого окна каждый переход выкидывал бы человека как «кражу».
 */
final class RememberToken extends Model
{
    protected static string $table = 'remember_tokens';

    protected bool $timestamps = false;

    /** Сколько живёт прежний validator после смены, секунд */
    private const GRACE = 60;

    /**
     * Выдаёт новый токен и возвращает значение куки.
     */
    public static function issue(int $userId, int $days, string $ip, string $agent): string
    {
        $selector  = Str::random(16);
        $validator = Str::random(48);

        $token = new self();

        $token->forceFill([
            'user_id'    => $userId,
            'selector'   => $selector,
            'token_hash' => self::hash($validator),
            'expires_at' => date('Y-m-d H:i:s', time() + max(1, $days) * 86400),
            'created_at' => Connection::now(),
            'ip'         => $ip,
            'user_agent' => mb_substr($agent, 0, 255),
        ])->save();

        return $selector . ':' . $validator;
    }

    /**
     * Находит токен по куке. Возвращает пару: сам токен и признак «пришли с
     * прежним validator в окно снисхождения».
     *
     * @return array{token: self|null, grace: bool}
     */
    public static function match(string $cookie): array
    {
        [$selector, $validator] = array_pad(explode(':', $cookie, 2), 2, '');

        if ($selector === '' || $validator === '') {
            return ['token' => null, 'grace' => false];
        }

        /** @var self|null $token */
        $token = self::query()->where('selector', $selector)->first();

        if ($token === null) {
            return ['token' => null, 'grace' => false];
        }

        if (strtotime((string) $token->raw('expires_at')) < time()) {
            $token->forceDelete();

            return ['token' => null, 'grace' => false];
        }

        if (hash_equals((string) $token->raw('token_hash'), self::hash($validator))) {
            return ['token' => $token, 'grace' => false];
        }

        $previous = (string) $token->raw('previous_hash');
        $rotated  = (int) strtotime((string) $token->raw('rotated_at'));

        if ($previous !== '' && hash_equals($previous, self::hash($validator)) && time() - $rotated <= self::GRACE) {
            return ['token' => $token, 'grace' => true];
        }

        // Кука с чужим или устаревшим validator: токен гасим целиком
        $token->forceDelete();

        return ['token' => null, 'grace' => false];
    }

    /**
     * Меняет validator, оставляя selector. Возвращает новое значение куки.
     */
    public function rotate(string $ip, string $agent): string
    {
        $validator = Str::random(48);

        $this->forceFill([
            'previous_hash' => $this->raw('token_hash'),
            'rotated_at'    => Connection::now(),
            'token_hash'    => self::hash($validator),
            'last_used_at'  => Connection::now(),
            'ip'            => $ip,
            'user_agent'    => mb_substr($agent, 0, 255),
        ])->save();

        return (string) $this->raw('selector') . ':' . $validator;
    }

    /**
     * Селектор из куки — по нему список устройств узнаёт текущее.
     */
    public static function selectorOf(string $cookie): string
    {
        return explode(':', $cookie, 2)[0] ?? '';
    }

    /**
     * Убирает просроченные токены — зовётся воркером.
     */
    public static function purge(): int
    {
        return self::query()->where('expires_at', '<', Connection::now())->forceDelete();
    }

    private static function hash(string $validator): string
    {
        return hash('sha256', $validator);
    }
}
