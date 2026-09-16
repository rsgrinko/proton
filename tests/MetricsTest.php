<?php

declare(strict_types=1);

/**
 * Показатели работы и присмотр за порогами.
 */

use Rsgrinko\Proton\Cache\Cache;
use Rsgrinko\Proton\Models\Setting;
use Rsgrinko\Proton\Support\Metrics;
use Rsgrinko\Proton\Support\Monitor;

test('показатели: запросы складываются, ошибки и медленные считаются отдельно', function (): void {
    Cache::flush();

    withConfig(['metrics.slow_ms' => 100], static function (): void {
        Metrics::track(10, 200);
        Metrics::track(30, 200);
        Metrics::track(500, 500);

        $stats = Metrics::requests(1);

        assertSame(3, $stats['requests']);
        assertSame(180.0, $stats['average_ms']);
        assertSame(1, $stats['errors']);
        assertSame(1, $stats['slow'], 'медленным считается ответ дольше порога');
    });

    Cache::flush();

    assertSame(0, Metrics::requests(1)['requests'], 'счётчики живут в кэше и чистятся вместе с ним');
});

test('показатели: сбор выключается настройкой', function (): void {
    Cache::flush();

    withConfig(['metrics.enabled' => false], static function (): void {
        Metrics::track(10, 200);

        assertSame(0, Metrics::requests(1)['requests']);
    });
});

test('показатели: снимок отвечает без запущенного сбора', function (): void {
    $snapshot = Metrics::snapshot();

    assertTrue(isset($snapshot['users']['total']), 'в снимке есть пользователи');
    assertTrue(isset($snapshot['queue']), 'и очередь');
    assertTrue(($snapshot['storage']['var_bytes'] ?? -1) >= 0);
});

test('показатели: тот же снимок читается в формате Prometheus', function (): void {
    $text = Metrics::prometheus();

    assertMatches('/^# HELP proton_users_total /m', $text);
    assertMatches('/^# TYPE proton_users_total gauge$/m', $text);
    assertMatches('/^proton_users_total \d+$/m', $text);

    // Метки берутся из состояний очереди — они всегда на месте, даже пустой
    assertMatches('/^proton_queue_jobs\{status="queued"\} \d+$/m', $text);

    // Пустой ключ разбивки превращается в «unknown», а не в кавычки без имени
    assertNotContains('{status=""}', $text);
});

test('присмотр: порог сработал — сообщение уходит один раз за окно', function (): void {
    Cache::flush();

    withConfig([
        'monitor.errors'         => 1,
        'monitor.repeat_minutes' => 60,
        'monitor.failed_jobs'    => 1000,
        'monitor.queue_backlog'  => 1000,
        'monitor.free_bytes'     => 0,
    ], static function (): void {
        Setting::set('monitor:alert:http.errors', '0');

        Metrics::track(5, 500);

        $problems = Monitor::check();

        assertTrue(isset($problems['http.errors']), 'ошибки 5xx выше порога — это проблема');

        $mark = (int) Setting::get('monitor:alert:http.errors', '0');

        assertTrue($mark > 0, 'отметка о сообщении поставлена');

        // Второй проход в том же окне отметку не двигает — значит, и не сообщает
        Monitor::check();

        assertSame($mark, (int) Setting::get('monitor:alert:http.errors', '0'));
    });

    Setting::set('monitor:alert:http.errors', '0');
    Cache::flush();
});
