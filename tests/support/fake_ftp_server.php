<?php

declare(strict_types=1);

/**
 * Игрушечный FTP-сервер на одну сессию — только для tests/FtpTest.php.
 * Настоящего FTP в проекте нет, поэтому Rsgrinko\Proton\Backup\Ftp проверяется
 * против него: разбор PASV, отправка данных, коды ошибок.
 *
 * Аргументы: <файл для порта> <файл назначения> [режим]
 * Режимы: ok (по умолчанию), auth-fail (PASS отвечает 530),
 * cwd-fail-then-mkd (первый CWD отвечает 550, дальше как обычно).
 */

$portFile = (string) ($argv[1] ?? '');
$destFile = (string) ($argv[2] ?? '');
$mode     = (string) ($argv[3] ?? 'ok');

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

if ($server === false) {
    fwrite(STDERR, $errstr);
    exit(1);
}

$name = (string) stream_socket_get_name($server, false);
$port = (int) substr($name, (int) strrpos($name, ':') + 1);

file_put_contents($portFile, (string) $port);

$control = @stream_socket_accept($server, 20);

if ($control === false) {
    exit(1);
}

fwrite($control, "220 fake ftp ready\r\n");

$cwdAttempts = 0;
$dataServer  = null;

while (!feof($control)) {
    $line = fgets($control, 1024);

    if ($line === false) {
        break;
    }

    $line = trim($line);

    if ($line === '') {
        continue;
    }

    $parts = explode(' ', $line, 2);
    $cmd   = strtoupper($parts[0]);

    switch ($cmd) {
        case 'USER':
            fwrite($control, "331 need password\r\n");

            break;

        case 'PASS':
            fwrite($control, $mode === 'auth-fail' ? "530 login incorrect\r\n" : "230 logged in\r\n");

            break;

        case 'TYPE':
            fwrite($control, "200 ok\r\n");

            break;

        case 'CWD':
            $cwdAttempts++;

            fwrite($control, $mode === 'cwd-fail-then-mkd' && $cwdAttempts === 1
                ? "550 no such directory\r\n"
                : "250 ok\r\n");

            break;

        case 'MKD':
            fwrite($control, "257 created\r\n");

            break;

        case 'PASV':
            $dataServer = stream_socket_server('tcp://127.0.0.1:0', $de, $ds);
            $dname      = (string) stream_socket_get_name($dataServer, false);
            $dport      = (int) substr($dname, (int) strrpos($dname, ':') + 1);
            $p1         = intdiv($dport, 256);
            $p2         = $dport % 256;

            fwrite($control, '227 Entering Passive Mode (127,0,0,1,' . $p1 . ',' . $p2 . ")\r\n");

            break;

        case 'STOR':
            fwrite($control, "150 opening data connection\r\n");

            $received = '';
            $data     = $dataServer !== null && $dataServer !== false ? @stream_socket_accept($dataServer, 20) : false;

            if ($data !== false) {
                while (!feof($data)) {
                    $chunk = fread($data, 65536);

                    if ($chunk === false) {
                        break;
                    }

                    $received .= $chunk;
                }

                fclose($data);
            }

            file_put_contents($destFile, $received);
            fwrite($control, "226 transfer complete\r\n");

            break;

        case 'QUIT':
            fwrite($control, "221 bye\r\n");

            break 2;

        default:
            fwrite($control, "500 unknown command\r\n");
    }
}

fclose($control);
