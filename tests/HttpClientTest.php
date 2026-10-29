<?php

declare(strict_types=1);

/**
 * HttpClient::fake() — подмена сети в тестах, реальная отправка проверена
 * там, где HttpClient используется, — tests/MailTest.php (MailerServiceDriver).
 */

use Rsgrinko\Proton\Support\HttpClient;

test('HttpClient: fake() отвечает заготовкой и запоминает запрос', function (): void {
    HttpClient::fake(['https://api.example.com/*' => ['status' => 201, 'body' => 'готово']]);

    try {
        $response = (new HttpClient())->post('https://api.example.com/orders', '{"id":1}', ['X-Test: да']);

        assertSame(201, $response['status']);
        assertSame('готово', $response['body']);

        $recorded = HttpClient::recorded();

        assertCount(1, $recorded);
        assertSame('POST', $recorded[0]['method']);
        assertSame('https://api.example.com/orders', $recorded[0]['url']);
        assertSame('{"id":1}', $recorded[0]['body']);
    } finally {
        HttpClient::reset();
    }
});

test('HttpClient: адрес без своей маски отвечает пустым 200, а не падением', function (): void {
    HttpClient::fake(['https://known.example.com/*' => ['status' => 500]]);

    try {
        $response = (new HttpClient())->get('https://unknown.example.com/');

        assertSame(200, $response['status']);
        assertSame('', $response['body']);
    } finally {
        HttpClient::reset();
    }
});

test('HttpClient: reset() выключает подмену и чистит журнал', function (): void {
    HttpClient::fake();
    (new HttpClient())->get('https://example.com');

    assertCount(1, HttpClient::recorded());

    HttpClient::reset();

    assertSame([], HttpClient::recorded());
});

test('HttpClient: ходит только по http и https', function (): void {
    // Даже под подменой: запрет не должен зависеть от того, тест это или бой
    HttpClient::fake(['*' => ['status' => 200, 'body' => 'не должно дойти']]);

    try {
        foreach (['file://localhost/etc/passwd', 'gopher://127.0.0.1:6379/_x', 'ftp://example.com/a'] as $url) {
            assertThrows(static fn () => (new HttpClient())->get($url), 'схема не должна пройти: ' . $url);
        }

        assertCount(0, HttpClient::recorded(), 'до сети ничего не ушло');
        assertTrue(HttpClient::allowedScheme('https://example.com/hook'));
        assertTrue(HttpClient::allowedScheme('http://10.0.0.5:8080/hook'), 'внутренние адреса по http разрешены');
    } finally {
        HttpClient::reset();
    }
});

test('HttpClient: список заготовок выдаётся по очереди, последний повторяется', function (): void {
    HttpClient::fake(['https://api.example.com/*' => [['status' => 500], ['status' => 502], ['status' => 200, 'body' => 'ок']]]);

    try {
        $client   = new HttpClient();
        $statuses = [];

        for ($i = 0; $i < 4; $i++) {
            $statuses[] = $client->get('https://api.example.com/orders/1')['status'];
        }

        assertSame([500, 502, 200, 200], $statuses);
        assertSame('ок', $client->get('https://api.example.com/orders/1')['body']);
    } finally {
        HttpClient::reset();
    }
});

test('HttpClient: заготовка-функция собирает ответ по запросу', function (): void {
    HttpClient::fake([
        'https://api.example.com/echo' => static fn (string $method, string $url, string $body): array => [
            'status' => 201,
            'body'   => $method . ' ' . $body,
        ],
    ]);

    try {
        $response = (new HttpClient())->post('https://api.example.com/echo', 'привет');

        assertSame(201, $response['status']);
        assertSame('POST привет', $response['body']);
    } finally {
        HttpClient::reset();
    }
});

test('HttpClient: reset() начинает список заготовок сначала', function (): void {
    $responses = ['*' => [['status' => 500], ['status' => 200]]];

    HttpClient::fake($responses);
    (new HttpClient())->get('https://example.com');
    HttpClient::fake($responses);

    try {
        assertSame(500, (new HttpClient())->get('https://example.com')['status']);
    } finally {
        HttpClient::reset();
    }
});

test('HttpClient: прокси разбирается сразу, кривой адрес не принимается', function (): void {
    $parts = HttpClient::parseProxy('socks5://bot%40home:p%3Ass@10.0.0.5:1080');

    assertSame(['type' => 'socks5', 'host' => '10.0.0.5', 'port' => 1080, 'user' => 'bot@home', 'pass' => 'p:ss'], $parts);
    assertSame('http://proxy.local:3128', (new HttpClient(5, ' http://proxy.local:3128 '))->proxy());
    assertSame('', (new HttpClient())->proxy(), 'без прокси — напрямую');

    foreach (['ftp://proxy:21', 'socks4://proxy:1080', 'http://proxy', 'proxy.local:3128'] as $proxy) {
        assertThrows(static fn () => new HttpClient(5, $proxy), 'не должен пройти: ' . $proxy);
    }

    assertSame('socks5://bot:***@10.0.0.5:1080', HttpClient::maskProxy('socks5://bot:secret@10.0.0.5:1080'));
});

test('HttpClient: прокси попадает в записанный запрос', function (): void {
    HttpClient::fake();

    try {
        (new HttpClient(5, 'socks5://10.0.0.5:1080'))->get('https://api.example.com/');
        (new HttpClient())->get('https://api.example.com/');

        assertSame('socks5://10.0.0.5:1080', HttpClient::recorded()[0]['proxy']);
        assertSame('', HttpClient::recorded()[1]['proxy']);
    } finally {
        HttpClient::reset();
    }
});

/**
 * Запрос через игрушечный прокси (tests/support/fake_proxy_server.php) и что
 * этот прокси увидел.
 *
 * @return array{response: array{status: int, body: string, duration: int}, report: array<string, mixed>}
 */
function requestThroughFakeProxy(string $mode, string $credentials, string $url): array
{
    $token    = bin2hex(random_bytes(4));
    $portFile = APP_ROOT . '/var/tmp/proxy-test-port-' . $token . '.txt';
    $logFile  = APP_ROOT . '/var/tmp/proxy-test-log-' . $token . '.json';

    $process = proc_open(
        ['php', APP_ROOT . '/tests/support/fake_proxy_server.php', $portFile, $logFile, $mode],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );

    try {
        $deadline = microtime(true) + 10;

        while (!is_file($portFile) && microtime(true) < $deadline) {
            usleep(10000);
        }

        $port     = (int) trim((string) @file_get_contents($portFile));
        $proxy    = ($mode === 'socks5' ? 'socks5' : 'http') . '://' . $credentials . '127.0.0.1:' . $port;
        $response = (new HttpClient(10, $proxy))->get($url);
        $report   = json_decode((string) @file_get_contents($logFile), true);

        return ['response' => $response, 'report' => is_array($report) ? $report : []];
    } finally {
        foreach ($pipes as $pipe) {
            @fclose($pipe);
        }

        @proc_terminate($process);
        @proc_close($process);
        @unlink($portFile);
        @unlink($logFile);
    }
}

test('HttpClient: запрос уходит через http-прокси с логином', function (): void {
    if (!function_exists('curl_init')) {
        skipTest('нет curl');
    }

    // Хост выдуманный: дойти запрос может только через прокси
    $result = requestThroughFakeProxy('http', 'bot:secret@', 'http://service.invalid/ping?x=1');

    assertSame(200, $result['response']['status']);
    assertSame('через прокси', $result['response']['body']);
    assertSame('GET http://service.invalid/ping?x=1 HTTP/1.1', $result['report']['request_line'] ?? '');
    assertSame('Basic ' . base64_encode('bot:secret'), $result['report']['proxy_authorization'] ?? '');
});

test('HttpClient: запрос уходит через socks5, имя хоста резолвит прокси', function (): void {
    if (!function_exists('curl_init')) {
        skipTest('нет curl');
    }

    $result = requestThroughFakeProxy('socks5', 'bot%40home:p%3Ass@', 'http://service.invalid:8080/ping');

    assertSame(200, $result['response']['status']);
    assertSame('через прокси', $result['response']['body']);
    assertSame('bot@home', $result['report']['user'] ?? '');
    assertSame('p:ss', $result['report']['pass'] ?? '');
    assertSame(3, $result['report']['address_type'] ?? 0, 'адрес именем, а не IP: DNS на стороне прокси');
    assertSame('service.invalid', $result['report']['target_host'] ?? '');
    assertSame(8080, $result['report']['target_port'] ?? 0);
    assertSame('GET /ping HTTP/1.1', $result['report']['request_line'] ?? '');
});
