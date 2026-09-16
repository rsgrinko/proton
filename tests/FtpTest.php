<?php

declare(strict_types=1);

/**
 * Отправка копии базы на FTP: свой клиент на потоках, без расширения ftp.
 *
 * Настоящего FTP-сервера в проекте нет, поэтому клиент проверяется против
 * tests/support/fake_ftp_server.php — игрушечного однопроходного сервера,
 * который понимает USER/PASS/TYPE/CWD/MKD/PASV/STOR/QUIT. Так протокол
 * (разбор PASV, порядок команд, коды ошибок) проверен по-настоящему,
 * а не угадан.
 */

use Rsgrinko\Proton\Backup\Ftp;
use Rsgrinko\Proton\Support\ProtonException;

/**
 * Поднимает игрушечный сервер и ждёт, пока он объявит порт.
 *
 * @return array{process: resource, pipes: array<int, resource>, port: int, portFile: string, destFile: string}
 */
function startFakeFtp(string $mode = 'ok'): array
{
    $token    = bin2hex(random_bytes(4));
    $portFile = APP_ROOT . '/var/tmp/ftp-test-port-' . $token . '.txt';
    $destFile = APP_ROOT . '/var/tmp/ftp-test-received-' . $token . '.bin';

    @unlink($portFile);
    @unlink($destFile);

    $process = proc_open(
        ['php', APP_ROOT . '/tests/support/fake_ftp_server.php', $portFile, $destFile, $mode],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );

    if (!is_resource($process)) {
        throw new RuntimeException('Не удалось запустить тестовый FTP-сервер');
    }

    $deadline = microtime(true) + 10;

    while (!is_file($portFile) && microtime(true) < $deadline) {
        usleep(10000);
    }

    if (!is_file($portFile)) {
        proc_terminate($process);

        throw new RuntimeException('Тестовый FTP-сервер не поднялся за 10 секунд');
    }

    return [
        'process'  => $process,
        'pipes'    => $pipes,
        'port'     => (int) trim((string) file_get_contents($portFile)),
        'portFile' => $portFile,
        'destFile' => $destFile,
    ];
}

/**
 * @param array{process: resource, pipes: array<int, resource>, port: int, portFile: string, destFile: string} $server
 */
function stopFakeFtp(array $server): void
{
    foreach ($server['pipes'] as $pipe) {
        if (is_resource($pipe)) {
            @fclose($pipe);
        }
    }

    if (is_resource($server['process'])) {
        @proc_terminate($server['process']);
        @proc_close($server['process']);
    }

    @unlink($server['portFile']);
    @unlink($server['destFile']);
}

/**
 * Отправляет тестовый файл через настоящий Ftp::upload() на фейковый сервер.
 */
function shipToFakeFtp(int $port, string $localPath, string $path = ''): void
{
    withConfig([
        'backup.ftp.host'     => '127.0.0.1',
        'backup.ftp.port'     => $port,
        'backup.ftp.username' => 'test',
        'backup.ftp.password' => 'secret',
        'backup.ftp.path'     => $path,
        'backup.ftp.timeout'  => 5,
    ], static function () use ($localPath): void {
        Ftp::upload($localPath);
    });
}

test('ftp: включён только когда задан хост', function (): void {
    withConfig(['backup.ftp.host' => ''], static function (): void {
        assertFalse(Ftp::enabled());
    });

    withConfig(['backup.ftp.host' => 'ftp.example.com'], static function (): void {
        assertTrue(Ftp::enabled());
    });
});

test('ftp: недоступный сервер — понятная ошибка, а не падение', function (): void {
    $file = APP_ROOT . '/var/tmp/ftp-test-unreachable.bin';

    file_put_contents($file, 'данные');

    // Порт, на котором точно никто не слушает
    $error = assertThrows(static fn () => shipToFakeFtp(1, $file));

    assertTrue($error instanceof ProtonException);
    assertContains('Не удалось подключиться', $error->getMessage());

    @unlink($file);
});

test('ftp: файл доезжает целиком через PASV', function (): void {
    $server = startFakeFtp();

    try {
        $file = APP_ROOT . '/var/tmp/ftp-test-source.bin';
        $body = str_repeat('proton-backup-', 5000); // больше одного чтения буфером

        file_put_contents($file, $body);

        shipToFakeFtp($server['port'], $file);

        assertSame($body, file_get_contents($server['destFile']));

        @unlink($file);
    } finally {
        stopFakeFtp($server);
    }
});

test('ftp: каталог заводится сам, если сервер ответил на CWD «нет такого»', function (): void {
    $server = startFakeFtp('cwd-fail-then-mkd');

    try {
        $file = APP_ROOT . '/var/tmp/ftp-test-mkd.bin';

        file_put_contents($file, 'x');

        shipToFakeFtp($server['port'], $file, 'proton/backups');

        assertSame('x', file_get_contents($server['destFile']));

        @unlink($file);
    } finally {
        stopFakeFtp($server);
    }
});

test('ftp: сервер отверг вход — сообщение доходит целиком', function (): void {
    $server = startFakeFtp('auth-fail');

    try {
        $file = APP_ROOT . '/var/tmp/ftp-test-auth.bin';

        file_put_contents($file, 'x');

        $error = assertThrows(static fn () => shipToFakeFtp($server['port'], $file));

        assertTrue($error instanceof ProtonException);
        assertContains('530', $error->getMessage());

        @unlink($file);
    } finally {
        stopFakeFtp($server);
    }
});

test('ftp: отправка несуществующего файла не достаёт до сети', function (): void {
    $error = assertThrows(static fn () => Ftp::upload(APP_ROOT . '/var/tmp/нет-такого-файла.bin'));

    assertTrue($error instanceof ProtonException);
    assertContains('Файла для отправки нет', $error->getMessage());
});
