<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Auth;

use Rsgrinko\Proton\Models\RememberToken;
use Rsgrinko\Proton\Models\UserSession;

/**
 * Список устройств человека — то, что он видит в своём профиле.
 *
 * Устройство — это не одна запись, а две разных: сессия (user_sessions) и долгая
 * кука «запомнить меня» (remember_tokens). Обычно они идут парой, но бывают и
 * порознь: вход без галки — сессия без куки, а браузер, где сессия кончилась, —
 * кука без сессии. Поэтому список собирается из обоих источников, а завершение
 * любой строки гасит и то, и другое.
 *
 * Текущее устройство узнаётся по своему имени сеанса и не гасится: выкидывать
 * себя из системы незачем.
 */
final class Devices
{
    /**
     * Устройства пользователя, свежие сверху.
     *
     * @return array<int, array{id: string, kind: string, ip: string, agent: string, created: string, last: string, current: bool}>
     */
    public static function of(int $userId): array
    {
        $currentSid      = Auth::currentSession();
        $currentSelector = Auth::currentSelector();

        $devices  = [];
        $selectors = [];

        /** @var array<int, UserSession> $sessions */
        $sessions = UserSession::query()
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->orderBy('last_seen_at', 'desc')
            ->get();

        foreach ($sessions as $session) {
            $selector = (string) $session->raw('remember_selector');

            if ($selector !== '') {
                $selectors[] = $selector;
            }

            $devices[] = [
                'id'      => 'session:' . (string) $session->id(),
                'kind'    => $selector !== '' ? 'сеанс с «запомнить меня»' : 'сеанс',
                'ip'      => (string) $session->raw('ip'),
                'agent'   => (string) $session->raw('user_agent'),
                'created' => (string) $session->raw('created_at'),
                'last'    => (string) $session->raw('last_seen_at'),
                'current' => $currentSid !== '' && hash('sha256', $currentSid) === (string) $session->raw('sid'),
            ];
        }

        /** @var array<int, RememberToken> $tokens */
        $tokens = RememberToken::query()
            ->where('user_id', $userId)
            ->orderBy('id', 'desc')
            ->get();

        foreach ($tokens as $token) {
            $selector = (string) $token->raw('selector');

            // Кука, у которой есть живая сессия, уже показана строкой выше
            if (in_array($selector, $selectors, true)) {
                continue;
            }

            $devices[] = [
                'id'      => 'remember:' . $selector,
                'kind'    => 'запомненный браузер',
                'ip'      => (string) $token->raw('ip'),
                'agent'   => (string) $token->raw('user_agent'),
                'created' => (string) $token->raw('created_at'),
                'last'    => (string) ($token->raw('last_used_at') ?? $token->raw('created_at')),
                'current' => $currentSelector !== '' && $currentSelector === $selector,
            ];
        }

        return $devices;
    }

    /**
     * Завершает устройство: гасит и сессию, и её долгую куку.
     */
    public static function revoke(int $userId, string $id): bool
    {
        [$kind, $value] = array_pad(explode(':', $id, 2), 2, '');

        if ($kind === 'session') {
            /** @var UserSession|null $session */
            $session = UserSession::query()->where('id', (int) $value)->where('user_id', $userId)->first();

            if ($session === null) {
                return false;
            }

            $session->revoke();

            return true;
        }

        if ($kind === 'remember' && $value !== '') {
            RememberToken::query()->where('selector', $value)->where('user_id', $userId)->forceDelete();

            return true;
        }

        return false;
    }
}
