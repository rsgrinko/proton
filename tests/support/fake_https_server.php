<?php

declare(strict_types=1);

/**
 * Игрушечный HTTPS-сервер — только для tests/HttpClientTest.php: проверка
 * сертификата по своему корневому файлу клиента идёт через настоящий TLS.
 *
 * Сертификат сервера (localhost, 127.0.0.1) подписан тестовым УЦ из
 * tests/support/tls/ca.pem; ключ УЦ не хранится — выпустить им что-то ещё
 * нельзя. Сервер принимает до <соединений> подключений и отвечает 200
 * «защищённо»; подключение, не прошедшее рукопожатие, тоже засчитывается.
 *
 * Аргументы: <файл для порта> <соединений>
 */

$portFile    = (string) ($argv[1] ?? '');
$connections = max(1, (int) ($argv[2] ?? 1));
$dir         = __DIR__ . '/tls';

$context = stream_context_create(['ssl' => [
    'local_cert'        => $dir . '/server.pem',
    'local_pk'          => $dir . '/server.key',
    'verify_peer'       => false,
    'allow_self_signed' => true,
]]);

$server = stream_socket_server('tls://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);

if ($server === false) {
    fwrite(STDERR, $errstr);
    exit(1);
}

$name = (string) stream_socket_get_name($server, false);

file_put_contents($portFile, substr($name, (int) strrpos($name, ':') + 1));

for ($i = 0; $i < $connections; $i++) {
    // Клиент, не доверяющий сертификату, рвёт рукопожатие — accept вернёт false
    $client = @stream_socket_accept($server, 20);

    if ($client === false) {
        continue;
    }

    stream_set_timeout($client, 10);

    $request = '';

    while (!feof($client) && !str_contains($request, "\r\n\r\n")) {
        $line = fgets($client, 4096);

        if ($line === false) {
            break;
        }

        $request .= $line;
    }

    $body = 'защищённо';

    fwrite($client, "HTTP/1.1 200 OK\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);
    fclose($client);
}
