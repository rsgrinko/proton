<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Support;

use Rsgrinko\Proton\Cache\Cache;
use Rsgrinko\Proton\Models\AuditEntry;
use Rsgrinko\Proton\Models\User;
use Rsgrinko\Proton\Models\UserNotification;
use Rsgrinko\Proton\Models\WebhookDelivery;
use Rsgrinko\Proton\Queue\Queue;

/**
 * Показатели работы: сколько запросов и с какой скоростью их обслужили,
 * что в очереди, как дела у вебхуков и базы.
 *
 * Счётчики запросов живут в кэше почасовыми ведёрками — своей таблицы под них
 * нет нарочно: показатели не данные, терять их не жалко, а лишняя запись
 * в базу на каждый запрос стоит дороже, чем стоят сами цифры.
 *
 * Снимок (`snapshot()`) считается из того, что и так лежит в базе, поэтому
 * не зависит от того, работал ли сбор.
 */
final class Metrics
{
    /** Сколько часов держим ведёрки счётчиков */
    private const KEEP_HOURS = 25;

    private const PREFIX = 'metrics:hour:';

    /**
     * Записать обслуженный запрос. Зовётся ядром в конце обработки.
     */
    public static function track(float $milliseconds, int $status): void
    {
        if (!(bool) Config::get('metrics.enabled', true)) {
            return;
        }

        $key    = self::key();
        $bucket = (array) (Cache::get($key) ?? ['requests' => 0, 'ms' => 0.0, 'errors' => 0, 'slow' => 0]);

        $bucket['requests'] = (int) $bucket['requests'] + 1;
        $bucket['ms']       = (float) $bucket['ms'] + $milliseconds;

        if ($status >= 500) {
            $bucket['errors'] = (int) $bucket['errors'] + 1;
        }

        if ($milliseconds >= (float) Config::get('metrics.slow_ms', 1000)) {
            $bucket['slow'] = (int) $bucket['slow'] + 1;
        }

        Cache::put($key, $bucket, self::KEEP_HOURS * 3600);
    }

    /**
     * Счётчики запросов за последние часы: сколько, как быстро, сколько ошибок.
     *
     * @return array{requests: int, average_ms: float, errors: int, slow: int, hours: int}
     */
    public static function requests(int $hours = 24): array
    {
        $requests = 0;
        $ms       = 0.0;
        $errors   = 0;
        $slow     = 0;

        for ($back = 0; $back < max(1, $hours); $back++) {
            $bucket = Cache::get(self::key(time() - $back * 3600));

            if (!is_array($bucket)) {
                continue;
            }

            $requests += (int) ($bucket['requests'] ?? 0);
            $ms       += (float) ($bucket['ms'] ?? 0);
            $errors   += (int) ($bucket['errors'] ?? 0);
            $slow     += (int) ($bucket['slow'] ?? 0);
        }

        return [
            'requests'   => $requests,
            'average_ms' => $requests > 0 ? round($ms / $requests, 1) : 0.0,
            'errors'     => $errors,
            'slow'       => $slow,
            'hours'      => max(1, $hours),
        ];
    }

    /**
     * Снимок состояния приложения — им же отвечает `/api/v1/metrics`.
     *
     * @return array<string, mixed>
     */
    public static function snapshot(): array
    {
        $day = date('Y-m-d H:i:s', time() - 86400);

        return [
            'time'     => date('c'),
            'app'      => [
                'name'    => (string) Config::get('app.name', 'Proton'),
                'env'     => (string) Config::get('app.env', 'production'),
                'php'     => PHP_VERSION,
                'memory'  => memory_get_peak_usage(true),
            ],
            'users'    => [
                'total'   => User::query()->count(),
                'active'  => User::query()->where('active', 1)->count(),
                'new_24h' => User::query()->where('created_at', '>=', $day)->count(),
            ],
            'requests' => self::requests(24),
            'queue'    => Queue::stats(),
            'webhooks' => Reports::breakdown(WebhookDelivery::query()->where('created_at', '>=', $day), 'status'),
            'audit'    => [
                'logins_24h' => AuditEntry::query()
                    ->where('action', AuditEntry::LOGIN)
                    ->where('created_at', '>=', $day)
                    ->count(),
                'total_24h'  => AuditEntry::query()->where('created_at', '>=', $day)->count(),
            ],
            'notifications' => [
                'unread' => UserNotification::query()->whereNull('read_at')->count(),
            ],
            'storage'  => self::storage(),
        ];
    }

    /**
     * Тот же снимок в текстовом формате Prometheus — строкой, без своего
     * клиента и без завязки на конкретный сборщик: любой Prometheus понимает
     * формат из коробки, Grafana тянет его через него же.
     *
     *     GET /metrics
     *
     * Открыт не всем подряд, а списку адресов (METRICS_ALLOW) — у сборщика
     * обычно нет API-ключа, зато есть свой адрес, с которого он ходит.
     */
    public static function prometheus(): string
    {
        $snapshot = self::snapshot();
        $lines    = [];

        $gauge = static function (string $name, string $help, int|float $value) use (&$lines): void {
            $lines[] = '# HELP ' . $name . ' ' . $help;
            $lines[] = '# TYPE ' . $name . ' gauge';
            $lines[] = $name . ' ' . $value;
        };

        $byLabel = static function (string $name, string $help, string $label, array $values) use (&$lines): void {
            $lines[] = '# HELP ' . $name . ' ' . $help;
            $lines[] = '# TYPE ' . $name . ' gauge';

            foreach ($values as $key => $value) {
                $key = (string) $key === '' || (string) $key === '—' ? 'unknown' : (string) $key;

                $lines[] = $name . '{' . $label . '="' . addcslashes($key, '"\\') . '"} ' . $value;
            }
        };

        $gauge('proton_users_total', 'Всего пользователей', $snapshot['users']['total']);
        $gauge('proton_users_active', 'Активных пользователей', $snapshot['users']['active']);
        $gauge('proton_users_new_24h', 'Заведено пользователей за сутки', $snapshot['users']['new_24h']);

        $gauge('proton_requests_24h', 'Запросов за сутки', $snapshot['requests']['requests'] ?? 0);
        $gauge('proton_requests_average_ms', 'Среднее время ответа за сутки, мс', $snapshot['requests']['average_ms'] ?? 0);
        $gauge('proton_requests_errors_24h', 'Ответов 5xx за сутки', $snapshot['requests']['errors'] ?? 0);
        $gauge('proton_requests_slow_24h', 'Медленных ответов за сутки', $snapshot['requests']['slow'] ?? 0);

        $byLabel('proton_queue_jobs', 'Задач в очереди по состоянию', 'status', $snapshot['queue']);
        $byLabel('proton_webhook_deliveries_24h', 'Посылок вебхуков за сутки по состоянию', 'status', $snapshot['webhooks']);

        $gauge('proton_audit_logins_24h', 'Входов за сутки', $snapshot['audit']['logins_24h']);
        $gauge('proton_audit_entries_24h', 'Записей в журнале за сутки', $snapshot['audit']['total_24h']);

        $gauge('proton_notifications_unread', 'Непрочитанных уведомлений', $snapshot['notifications']['unread']);

        $byLabel('proton_storage_bytes', 'Место в байтах: база, var и свободно на диске', 'kind', [
            'database' => (int) $snapshot['storage']['database_bytes'],
            'var'      => (int) $snapshot['storage']['var_bytes'],
            'free'     => (int) $snapshot['storage']['free_bytes'],
        ]);

        return implode("\n", $lines) . "\n";
    }

    /**
     * Место: размер базы, папки var и сколько свободно на диске.
     *
     * @return array<string, int|string>
     */
    public static function storage(): array
    {
        $database = (string) Config::get('db.sqlite.path', APP_ROOT . '/var/app.sqlite');
        $free     = @disk_free_space(APP_ROOT);

        return [
            'database_bytes' => is_file($database) ? (int) filesize($database) : 0,
            'var_bytes'      => self::size(APP_ROOT . '/var'),
            'free_bytes'     => $free === false ? 0 : (int) $free,
        ];
    }

    /**
     * Размер папки. Обходим без рекурсии по всему дереву глубоко: var/ и так
     * плоская, а считать миллион файлов на каждый запрос незачем.
     */
    private static function size(string $path): int
    {
        if (!is_dir($path)) {
            return 0;
        }

        $total = 0;

        foreach ((array) glob($path . '/*') as $item) {
            if (is_file((string) $item)) {
                $total += (int) filesize((string) $item);

                continue;
            }

            foreach ((array) glob($item . '/*') as $nested) {
                if (is_file((string) $nested)) {
                    $total += (int) filesize((string) $nested);
                }
            }
        }

        return $total;
    }

    private static function key(?int $time = null): string
    {
        return self::PREFIX . date('Y-m-d-H', $time ?? time());
    }
}
