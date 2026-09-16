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
