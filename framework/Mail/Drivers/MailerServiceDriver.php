<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Mail\Drivers;

use Rsgrinko\Proton\Mail\Message;
use Rsgrinko\Proton\Support\Config;
use Rsgrinko\Proton\Support\HttpClient;
use Rsgrinko\Proton\Support\ProtonException;
use Rsgrinko\Proton\Support\RequestId;

/**
 * Отправка через внешний почтовый сервис по HTTP API.
 *
 * Письмо уходит одним запросом POST /api/v1/messages с ключом проекта в
 * заголовке Authorization. Очередь, повторы, стоп-лист, DKIM и отчёты о доставке
 * остаются на стороне сервиса — приложению нужно только отдать письмо.
 *
 * Адрес и ключ задаются настройками MAIL_SERVICE_URL и MAIL_SERVICE_KEY.
 * curl не обязателен: если расширения нет, запрос уходит потоками.
 */
final class MailerServiceDriver implements DriverInterface
{
    public function send(Message $message): void
    {
        $url = rtrim((string) Config::get('mail.service.url', ''), '/');
        $key = (string) Config::get('mail.service.key', '');

        if ($url === '' || $key === '') {
            throw new ProtonException('Для драйвера mailer нужны MAIL_SERVICE_URL и MAIL_SERVICE_KEY');
        }

        $payload = $message->toArray();

        // Сервис ждёт получателей строкой или списком — отдаём список,
        // а пустые поля не шлём вовсе, чтобы не спорить с его валидацией
        $body = array_filter([
            'to'          => $payload['to'],
            'cc'          => $payload['cc'],
            'bcc'         => $payload['bcc'],
            'from'        => $payload['from'],
            'from_name'   => $payload['from_name'],
            'reply_to'    => $payload['reply_to'],
            'subject'     => $payload['subject'],
            'text'        => $payload['text'],
            'html'        => $payload['html'],
            'headers'     => $payload['headers'],
            'attachments' => $payload['attachments'],
        ], static fn (mixed $value): bool => $value !== '' && $value !== []);

        $response = $this->post(
            $url . '/api/v1/messages',
            (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $key,
                // Своя цепочка запросов доезжает до сервиса и находится в его логах
                RequestId::HEADER . ': ' . RequestId::current(),
            ]
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            $decoded = json_decode($response['body'], true);
            $detail  = is_array($decoded) ? (string) ($decoded['error']['message'] ?? '') : '';

            throw new ProtonException(
                'Почтовый сервис ответил ' . $response['status']
                . ($detail !== '' ? ': ' . $detail : '')
            );
        }
    }

    public function name(): string
    {
        return 'mailer';
    }

    /**
     * @param array<int, string> $headers
     *
     * @return array{status: int, body: string}
     */
    private function post(string $url, string $body, array $headers): array
    {
        $timeout = max(2, (int) Config::get('mail.service.timeout', 10));

        try {
            return (new HttpClient($timeout))->post($url, $body, $headers);
        } catch (ProtonException $e) {
            throw new ProtonException('Почтовый сервис недоступен: ' . $e->getMessage());
        }
    }
}
