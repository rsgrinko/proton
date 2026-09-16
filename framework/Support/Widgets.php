<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Support;

use Rsgrinko\Proton\Access\Permission;
use Rsgrinko\Proton\Access\Viewer;
use Rsgrinko\Proton\Cache\Cache;
use Rsgrinko\Proton\Models\User;
use Rsgrinko\Proton\Queue\Queue;

/**
 * Карточки на «Обзоре» — какие показывать, решает право, а не один список
 * на всех. Тот же принцип, что у прав и событий вебхуков: реестр в коде
 * (свои — в `config/widgets.php`), в базе ничего не лежит.
 *
 *     Widgets::register('orders', 'Заказы', 'orders.view', static fn (): array => [
 *         'value' => Order::query()->count(),
 *         'route' => 'orders.index',
 *     ]);
 *
 * Пустое право показывает карточку всем вошедшим на «Обзор» — сейчас таких
 * нет: даже к самому «Обзору» пускают по `system.view`.
 */
final class Widgets
{
    /** @var array<string, array{title: string, permission: string, render: callable}>|null */
    private static ?array $items = null;

    /**
     * @param callable(): array{value?: int|string, hint?: string, route?: string, badge?: string, badgeLabel?: string} $render
     */
    public static function register(string $key, string $title, string $permission, callable $render): void
    {
        self::boot();

        self::$items[$key] = ['title' => $title, 'permission' => $permission, 'render' => $render];
    }

    /**
     * Карточки, доступные этому человеку, — с уже посчитанными данными.
     * Каждая своим коротким кэшем: обзор не должен считать заново на каждое
     * обновление страницы, но и держать чужой 30-секундный снимок ради одной
     * карточки не стоит.
     *
     * @return array<string, array{title: string, value?: int|string, hint?: string, route?: string, badge?: string, badgeLabel?: string}>
     */
    public static function for(Viewer $viewer): array
    {
        self::boot();

        $result = [];

        foreach (self::$items as $key => $item) {
            if ($item['permission'] !== '' && !$viewer->can($item['permission'])) {
                continue;
            }

            $data = Cache::remember('widget:' . $key, 30, $item['render']);

            $result[$key] = ['title' => $item['title']] + $data;
        }

        return $result;
    }

    /**
     * Забыть реестр — нужно тестам.
     */
    public static function reset(): void
    {
        self::$items = null;
    }

    private static function boot(): void
    {
        if (self::$items !== null) {
            return;
        }

        self::$items = [];

        self::core();

        $file = APP_ROOT . '/config/widgets.php';

        if (is_file($file)) {
            require $file;
        }
    }

    /**
     * Карточки ядра: те же, что были на «Обзоре» раньше, только каждая
     * теперь смотрит на своё право, а не показывается всем подряд.
     */
    private static function core(): void
    {
        self::register('users', 'Пользователей', Permission::USERS_MANAGE, static function (): array {
            return [
                'value' => User::query()->count(),
                'hint'  => 'активных: ' . User::query()->where('active', 1)->count(),
                'route' => 'admin.users',
            ];
        });

        self::register('queue', 'В очереди', Permission::SYSTEM_MANAGE, static function (): array {
            $stats = Queue::stats();

            return [
                'value' => (int) ($stats[Queue::QUEUED] ?? 0),
                'hint'  => 'с ошибкой: ' . (int) ($stats[Queue::FAILED] ?? 0),
                'route' => 'admin.queue',
            ];
        });

        self::register('health', 'Состояние', Permission::SYSTEM_VIEW, static function (): array {
            $checks = Diagnostics::run();
            $worst  = Diagnostics::worst($checks);

            $labels = ['ok' => 'всё в порядке', 'warn' => 'есть замечания', 'error' => 'есть проблемы'];

            return [
                'badge'      => $worst,
                'badgeLabel' => $labels[$worst] ?? $worst,
                'hint'       => 'подробности на странице состояния',
                'route'      => 'admin.system',
            ];
        });
    }
}
