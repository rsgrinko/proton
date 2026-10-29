<?php

declare(strict_types=1);

/**
 * Игрушечный прокси на одно соединение — только для tests/HttpClientTest.php.
 * Настоящего прокси в окружении тестов нет, а проверить нужно настоящую ветку
 * curl: что запрос ушёл через прокси, с логином и с именем хоста, которое
 * резолвит прокси, а не мы.
 *
 * Аргументы: <файл для порта> <файл отчёта> <режим: http|socks5>
 * Отчёт — JSON: что прокси увидел (строка запроса, логин, хост назначения).
 * Ответ на любой запрос — 200 с телом «через прокси».
 */

$portFile = (string) ($argv[1] ?? '');
$logFile  = (string) ($argv[2] ?? '');
$mode     = (string) ($argv[3] ?? 'http');

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

if ($server === false) {
    fwrite(STDERR, $errstr);
    exit(1);
}

$name = (string) stream_socket_get_name($server, false);

file_put_contents($portFile, substr($name, (int) strrpos($name, ':') + 1));

$client = @stream_socket_accept($server, 20);

if ($client === false) {
    exit(1);
}

stream_set_timeout($client, 10);

/**
 * Ровно $length байт: fread на сокете отдаёт сколько пришло.
 */
function readExactly($socket, int $length): string
{
    $data = '';

    while (strlen($data) < $length && !feof($socket)) {
        $chunk = fread($socket, $length - strlen($data));

        if ($chunk === false || $chunk === '') {
            break;
        }

        $data .= $chunk;
    }

    return $data;
}

$report = ['mode' => $mode];

if ($mode === 'socks5') {
    // Приветствие: версия, число способов входа, сами способы
    [, $count] = array_values(unpack('C2', readExactly($client, 2)));
    $methods   = array_values(unpack('C*', readExactly($client, $count)));

    if (in_array(2, $methods, true)) {
        fwrite($client, "\x05\x02");

        // Логин и пароль по RFC 1929
        readExactly($client, 1);
        $user = readExactly($client, ord(readExactly($client, 1)));
        $pass = readExactly($client, ord(readExactly($client, 1)));

        $report['user'] = $user;
        $report['pass'] = $pass;

        fwrite($client, "\x01\x00");
    } else {
        fwrite($client, "\x05\x00");
    }

    // Запрос на соединение: версия, команда, резерв, тип адреса
    $head = array_values(unpack('C4', readExactly($client, 4)));

    $report['address_type'] = $head[3];
    $report['target_host']  = match ($head[3]) {
        3       => readExactly($client, ord(readExactly($client, 1))),
        1       => inet_ntop(readExactly($client, 4)),
        default => '',
    };
    $report['target_port'] = unpack('n', readExactly($client, 2))[1];

    fwrite($client, "\x05\x00\x00\x01\x00\x00\x00\x00\x00\x00");
}

// Дальше — обычный HTTP: прокси или туннель, нам всё равно
$request = '';

while (!feof($client) && !str_contains($request, "\r\n\r\n")) {
    $line = fgets($client, 4096);

    if ($line === false) {
        break;
    }

    $request .= $line;
}

$lines = explode("\r\n", trim($request));

$report['request_line'] = $lines[0] ?? '';

foreach ($lines as $line) {
    if (stripos($line, 'Proxy-Authorization:') === 0) {
        $report['proxy_authorization'] = trim(substr($line, strlen('Proxy-Authorization:')));
    }
}

// Отчёт пишем до ответа: тест читает его сразу, как только получил ответ
file_put_contents($logFile, json_encode($report, JSON_UNESCAPED_UNICODE));

$body = 'через прокси';

fwrite($client, "HTTP/1.1 200 OK\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);
fclose($client);
