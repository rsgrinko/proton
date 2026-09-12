<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Mail\Drivers;

use Rsgrinko\Proton\Mail\Message;
use Rsgrinko\Proton\Mail\Mime;
use Rsgrinko\Proton\Support\Config;
use Rsgrinko\Proton\Support\ProtonException;

/**
 * Свой SMTP-клиент на потоках: ни composer, ни расширений, кроме openssl.
 *
 * Понимает голое соединение, SSL (порт 465) и STARTTLS (587), вход LOGIN и PLAIN —
 * выбирается тот способ, который сервер объявил в ответе на EHLO. Соединение
 * живёт от письма к письму: перед каждым следующим идёт RSET, он же проверка,
 * что сервер не закрыл связь.
 */
final class SmtpDriver implements DriverInterface
{
    /** @var resource|null */
    private $socket = null;

    /** @var array<int, string> Что сервер объявил в ответе на EHLO */
    private array $capabilities = [];

    private int $sentInSession = 0;

    public function send(Message $message): void
    {
        $this->connect();

        try {
            $this->deliver($message);
        } catch (ProtonException $e) {
            // Сессию после ошибки не переиспользуем: состояние диалога уже неясно
            $this->close();

            throw $e;
        }

        $this->sentInSession++;

        $limit = (int) Config::get('mail.smtp.session_limit', 100);

        if ($limit > 0 && $this->sentInSession >= $limit) {
            $this->close();
        }
    }

    public function name(): string
    {
        return 'smtp';
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * Закрывает сессию — воркер зовёт это, когда очередь опустела.
     */
    public function close(): void
    {
        if (is_resource($this->socket)) {
            @fwrite($this->socket, "QUIT\r\n");
            @fclose($this->socket);
        }

        $this->socket        = null;
        $this->capabilities  = [];
        $this->sentInSession = 0;
    }

    private function deliver(Message $message): void
    {
        $this->command('MAIL FROM:<' . $this->assertAddress($message->senderEmail()) . '>', [250]);

        foreach ($message->envelopeRecipients() as $recipient) {
            $this->command('RCPT TO:<' . $this->assertAddress($recipient) . '>', [250, 251]);
        }

        $this->command('DATA', [354]);

        // Точка в начале строки — конец письма для SMTP, поэтому её удваивают
        $body = preg_replace('/^\./m', '..', Mime::build($message)) ?? '';

        $this->write($body . "\r\n.\r\n");
        $this->expect([250]);
    }

    /**
     * Поднимает соединение, если его ещё нет или сервер его закрыл.
     */
    private function connect(): void
    {
        if (is_resource($this->socket)) {
            // RSET заодно проверяет, жива ли связь
            try {
                $this->command('RSET', [250]);

                return;
            } catch (ProtonException) {
                $this->close();
            }
        }

        $host       = (string) Config::get('mail.smtp.host', '127.0.0.1');
        $port       = (int) Config::get('mail.smtp.port', 25);
        $encryption = strtolower((string) Config::get('mail.smtp.encryption', ''));
        $timeout    = max(5, (int) Config::get('mail.smtp.timeout', 30));

        $address = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;

        $context = stream_context_create([
            'ssl' => [
                'verify_peer'       => (bool) Config::get('mail.smtp.verify_peer', true),
                'verify_peer_name'  => (bool) Config::get('mail.smtp.verify_peer', true),
                'allow_self_signed' => !(bool) Config::get('mail.smtp.verify_peer', true),
            ],
        ]);

        $socket = @stream_socket_client($address, $code, $error, $timeout, STREAM_CLIENT_CONNECT, $context);

        if ($socket === false) {
            throw new ProtonException('Не удалось подключиться к SMTP ' . $address . ': ' . $error . ' (' . $code . ')');
        }

        stream_set_timeout($socket, $timeout);

        $this->socket = $socket;

        $this->expect([220]);
        $this->hello();

        if ($encryption === 'tls') {
            $this->command('STARTTLS', [220]);

            if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                $last = error_get_last();

                $this->close();

                // Причина обычно не в сервере, а в самом PHP: не собран openssl.cafile
                throw new ProtonException('Не удалось установить TLS: ' . (string) ($last['message'] ?? 'причина неизвестна'));
            }

            // После STARTTLS список возможностей объявляется заново
            $this->hello();
        }

        $this->authenticate();
    }

    private function hello(): void
    {
        $name     = (string) Config::get('mail.smtp.helo', '') ?: (string) (gethostname() ?: 'localhost');
        $response = $this->command('EHLO ' . $name, [250], true);

        $this->capabilities = array_map('strtoupper', explode("\n", $response));
    }

    private function authenticate(): void
    {
        $user     = (string) Config::get('mail.smtp.username', '');
        $password = (string) Config::get('mail.smtp.password', '');

        if ($user === '') {
            return;
        }

        $supports = static fn (array $lines, string $needle): bool => array_filter(
            $lines,
            static fn (string $line): bool => str_contains($line, $needle)
        ) !== [];

        if ($supports($this->capabilities, 'AUTH') && $supports($this->capabilities, 'PLAIN')) {
            $this->command('AUTH PLAIN ' . base64_encode("\0" . $user . "\0" . $password), [235]);

            return;
        }

        $this->command('AUTH LOGIN', [334]);
        $this->command(base64_encode($user), [334]);
        $this->command(base64_encode($password), [235]);
    }

    /**
     * Отправляет команду и проверяет код ответа.
     *
     * @param array<int, int> $expected
     */
    private function command(string $command, array $expected, bool $multiline = false): string
    {
        $this->write($command . "\r\n");

        return $this->expect($expected, $multiline);
    }

    private function write(string $data): void
    {
        if (!is_resource($this->socket) || @fwrite($this->socket, $data) === false) {
            throw new ProtonException('Соединение с SMTP-сервером потеряно');
        }
    }

    /**
     * Читает ответ сервера и сверяет код.
     *
     * @param array<int, int> $expected
     */
    private function expect(array $expected, bool $multiline = false): string
    {
        if (!is_resource($this->socket)) {
            throw new ProtonException('Соединение с SMTP-сервером не открыто');
        }

        $lines = [];

        do {
            $line = fgets($this->socket, 1024);

            if ($line === false) {
                throw new ProtonException('SMTP-сервер закрыл соединение');
            }

            $lines[] = trim($line);

            // Многострочный ответ: у всех строк, кроме последней, после кода стоит дефис
            $more = strlen($line) > 3 && $line[3] === '-';
        } while ($more);

        $last = end($lines) ?: '';
        $code = (int) substr($last, 0, 3);

        if (!in_array($code, $expected, true)) {
            throw new ProtonException('SMTP ответил «' . $last . '», ожидался код ' . implode(' или ', $expected));
        }

        return $multiline ? implode("\n", $lines) : $last;
    }

    /**
     * Адрес конверта не должен содержать пробелов, скобок и управляющих символов:
     * иначе в команду SMTP уезжает вторая строка.
     */
    private function assertAddress(string $address): string
    {
        $address = trim($address);

        if ($address === '' || preg_match('/^[^\s<>(),;:"\\\\\x00-\x1f]+@[^\s<>(),;:"\\\\\x00-\x1f]+$/', $address) !== 1) {
            throw new ProtonException('Недопустимый адрес для SMTP: ' . $address);
        }

        return $address;
    }
}
