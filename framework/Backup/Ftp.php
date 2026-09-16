<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Backup;

use Rsgrinko\Proton\Support\Config;
use Rsgrinko\Proton\Support\ProtonException;

/**
 * Отправка файла на FTP свой реализацией на потоках — как SMTP-клиент у почты:
 * ни composer, ни расширения `ftp`, только `stream_socket_client`.
 *
 * Держит только пассивный режим (PASV): сервер сообщает адрес и порт для
 * данных, клиент сам к ним подключается — это то, что проходит сквозь
 * обычный домашний NAT без проброса портов. Активный режим (PORT) тут не
 * нужен: обычная задача — копия с сервера наружу, а не наоборот.
 *
 * Соединение живёт одну отправку: копии уезжают раз в сутки, держать сессию
 * между ними незачем.
 */
final class Ftp
{
    /** @var resource|null */
    private $control = null;

    /**
     * Включён ли вообще (задан хост).
     */
    public static function enabled(): bool
    {
        return trim((string) Config::get('backup.ftp.host', '')) !== '';
    }

    /**
     * Отправляет локальный файл в настроенный каталог на FTP тем же именем.
     */
    public static function upload(string $localPath): void
    {
        if (!is_file($localPath)) {
            throw new ProtonException('Файла для отправки нет: ' . $localPath);
        }

        (new self())->send($localPath);
    }

    private function send(string $localPath): void
    {
        try {
            $this->connect();
            $this->login();
            $this->binary();
            $this->changeDirectory();
            $this->store($localPath);
            $this->command('QUIT', []);
        } finally {
            $this->close();
        }
    }

    private function connect(): void
    {
        $host    = (string) Config::get('backup.ftp.host', '');
        $port    = (int) Config::get('backup.ftp.port', 21);
        $timeout = max(5, (int) Config::get('backup.ftp.timeout', 30));

        $socket = @stream_socket_client('tcp://' . $host . ':' . $port, $code, $error, $timeout);

        if ($socket === false) {
            throw new ProtonException('Не удалось подключиться к FTP ' . $host . ':' . $port . ': ' . $error . ' (' . $code . ')');
        }

        stream_set_timeout($socket, $timeout);

        $this->control = $socket;

        $this->expect($this->control, [220]);
    }

    private function login(): void
    {
        $user     = (string) Config::get('backup.ftp.username', '');
        $password = (string) Config::get('backup.ftp.password', '');

        $response = $this->command('USER ' . ($user !== '' ? $user : 'anonymous'), [230, 331]);

        if (str_starts_with($response, '331')) {
            $this->command('PASS ' . $password, [230]);
        }
    }

    private function binary(): void
    {
        // Копия — уже сжатый бинарный файл: текстовый режим (ASCII) перевёл
        // бы переводы строк и испортил архив
        $this->command('TYPE I', [200]);
    }

    /**
     * Переходит в настроенный каталог, если он задан. Каталога нет — заводит
     * сам и переходит снова: то же самое ожидание, что и от локального
     * каталога копий (`Backup::directory()`).
     */
    private function changeDirectory(): void
    {
        $path = trim((string) Config::get('backup.ftp.path', ''), '/');

        if ($path === '') {
            return;
        }

        $response = $this->command('CWD /' . $path, [250, 550]);

        if (str_starts_with($response, '250')) {
            return;
        }

        $this->command('MKD /' . $path, [257]);
        $this->command('CWD /' . $path, [250]);
    }

    /**
     * Пассивный режим и передача файла.
     */
    private function store(string $localPath): void
    {
        $response = $this->command('PASV', [227]);

        $data = $this->openPassive($response);

        try {
            $this->command('STOR ' . basename($localPath), [150, 125]);

            $in = fopen($localPath, 'rb');

            if ($in === false) {
                throw new ProtonException('Не удалось открыть файл для отправки: ' . $localPath);
            }

            try {
                while (!feof($in)) {
                    $chunk = fread($in, 262144);

                    if ($chunk === false || $chunk === '') {
                        continue;
                    }

                    if (@fwrite($data, $chunk) === false) {
                        throw new ProtonException('Обрыв соединения с данными FTP при отправке ' . basename($localPath));
                    }
                }
            } finally {
                fclose($in);
            }
        } finally {
            fclose($data);
        }

        // Ответ на STOR приходит только после того, как сервер закрыл с
        // своей стороны канал данных — поэтому читаем его лишь теперь
        $this->expect($this->control, [226, 250]);
    }

    /**
     * Разбирает ответ на PASV («227 Entering Passive Mode (h1,h2,h3,h4,p1,p2)»)
     * и открывает к нему соединение данных.
     *
     * @return resource
     */
    private function openPassive(string $response)
    {
        if (preg_match('/\((\d+),(\d+),(\d+),(\d+),(\d+),(\d+)\)/', $response, $m) !== 1) {
            throw new ProtonException('Не разобрать ответ на PASV: ' . $response);
        }

        $ip   = $m[1] . '.' . $m[2] . '.' . $m[3] . '.' . $m[4];
        $port = ((int) $m[5] << 8) + (int) $m[6];

        $timeout = max(5, (int) Config::get('backup.ftp.timeout', 30));

        $data = @stream_socket_client('tcp://' . $ip . ':' . $port, $code, $error, $timeout);

        if ($data === false) {
            throw new ProtonException('Не удалось открыть канал данных FTP ' . $ip . ':' . $port . ': ' . $error);
        }

        stream_set_timeout($data, $timeout);

        return $data;
    }

    /**
     * Отправляет команду по управляющему соединению и проверяет код ответа.
     *
     * @param array<int, int> $expected
     */
    private function command(string $command, array $expected): string
    {
        if (!is_resource($this->control) || @fwrite($this->control, $command . "\r\n") === false) {
            throw new ProtonException('Соединение с FTP потеряно на команде ' . strtok($command, ' '));
        }

        return $expected === [] ? '' : $this->expect($this->control, $expected);
    }

    /**
     * @param resource $socket
     * @param array<int, int> $expected
     */
    private function expect($socket, array $expected): string
    {
        $line = '';

        do {
            $chunk = fgets($socket, 1024);

            if ($chunk === false) {
                throw new ProtonException('FTP-сервер не ответил или закрыл соединение');
            }

            $line = trim($chunk);

            // Многострочный ответ: у первой строки после кода стоит дефис,
            // конец — строка с тем же кодом и пробелом
        } while (strlen($line) > 3 && $line[3] === '-');

        $code = (int) substr($line, 0, 3);

        if (!in_array($code, $expected, true)) {
            throw new ProtonException('FTP ответил «' . $line . '», ожидался код ' . implode(' или ', $expected));
        }

        return $line;
    }

    private function close(): void
    {
        if (is_resource($this->control)) {
            @fclose($this->control);
        }

        $this->control = null;
    }
}
