<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Events;

use Rsgrinko\Proton\Support\Logger;
use Throwable;

/**
 * Шина событий: код объявляет, что произошло, а слушатели решают, что с этим делать.
 *
 *     Events::listen('user.registered', function (array $data) { … });
 *     Events::fire('user.registered', ['user' => $user]);
 *
 * Слушатели подключаются в config/events.php — там видно сразу все подписки,
 * а не по одной в разных концах приложения.
 *
 * Упавший слушатель не роняет то, что его позвало: событие — это уведомление,
 * а не часть действия. Ошибка уходит в лог.
 */
final class Events
{
    /** @var array<string, array<int, array{callback: callable, priority: int}>> */
    private static array $listeners = [];

    private static bool $booted = false;

    /**
     * Подписаться. Чем больше приоритет, тем раньше слушатель отработает.
     */
    public static function listen(string $event, callable $callback, int $priority = 0): void
    {
        self::$listeners[$event][] = ['callback' => $callback, 'priority' => $priority];

        usort(
            self::$listeners[$event],
            static fn (array $a, array $b): int => $b['priority'] <=> $a['priority']
        );
    }

    /**
     * Объявить событие. Возвращает то, что вернули слушатели.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<int, mixed>
     */
    public static function fire(string $event, array $payload = []): array
    {
        self::boot();

        $results = [];

        foreach (self::$listeners[$event] ?? [] as $listener) {
            try {
                $results[] = ($listener['callback'])($payload, $event);
            } catch (Throwable $e) {
                (new Logger('events'))->error('Слушатель события упал', [
                    'event' => $event,
                    'error' => $e->getMessage(),
                    'file'  => $e->getFile() . ':' . $e->getLine(),
                ]);
            }
        }

        return $results;
    }

    /**
     * Событие-фильтр: значение проходит через слушателей по цепочке, каждый может
     * его изменить. Так работают хуки вроде «чем отправлять письмо».
     *
     * @param array<string, mixed> $payload
     */
    public static function filter(string $event, mixed $value, array $payload = []): mixed
    {
        self::boot();

        foreach (self::$listeners[$event] ?? [] as $listener) {
            try {
                $result = ($listener['callback'])($value, $payload, $event);

                if ($result !== null) {
                    $value = $result;
                }
            } catch (Throwable $e) {
                (new Logger('events'))->error('Фильтр события упал', [
                    'event' => $event,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $value;
    }

    public static function has(string $event): bool
    {
        self::boot();

        return (self::$listeners[$event] ?? []) !== [];
    }

    /**
     * Все объявленные подписки — страница состояния показывает их списком.
     *
     * @return array<string, int>
     */
    public static function registered(): array
    {
        self::boot();

        $result = [];

        foreach (self::$listeners as $event => $listeners) {
            $result[$event] = count($listeners);
        }

        ksort($result);

        return $result;
    }

    /**
     * Забыть подписки — нужно тестам.
     */
    public static function reset(): void
    {
        self::$listeners = [];
        self::$booted    = false;
    }

    /**
     * Подключает config/events.php при первом обращении.
     */
    private static function boot(): void
    {
        if (self::$booted) {
            return;
        }

        self::$booted = true;

        $file = APP_ROOT . '/config/events.php';

        if (is_file($file)) {
            require $file;
        }
    }
}
