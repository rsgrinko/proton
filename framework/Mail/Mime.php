<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Mail;

/**
 * Сборка письма в MIME: заголовки и тело.
 *
 * Русские темы и имена кодируются base64 (=?UTF-8?B?…?=) — иначе почтовые
 * серверы получают восьмибитные байты в заголовке и портят их. Тело идёт
 * quoted-printable, вложения — base64 кусками по 76 символов, как требует
 * стандарт.
 */
final class Mime
{
    /**
     * Заголовки письма без тела.
     *
     * @return array<string, string>
     */
    public static function headers(Message $message, bool $withRecipients = true): array
    {
        $headers = [];

        if ($withRecipients) {
            $headers['To'] = implode(', ', $message->recipients());

            if ($message->copies() !== []) {
                $headers['Cc'] = implode(', ', $message->copies());
            }
        }

        $headers['From'] = self::address($message->senderEmail(), $message->senderName());

        if ($message->replyAddress() !== '') {
            $headers['Reply-To'] = $message->replyAddress();
        }

        $headers['Subject']      = self::encodeHeader($message->subjectLine());
        $headers['Date']         = date('r');
        $headers['Message-ID']   = '<' . bin2hex(random_bytes(12)) . '@' . self::hostname($message->senderEmail()) . '>';
        $headers['MIME-Version'] = '1.0';

        foreach ($message->extraHeaders() as $name => $value) {
            $headers[$name] = self::encodeHeader($value);
        }

        return $headers;
    }

    /**
     * Тело письма вместе с заголовками Content-Type.
     *
     * @return array{headers: array<string, string>, body: string}
     */
    public static function body(Message $message): array
    {
        $text = $message->textBody();
        $html = $message->htmlBody();

        $attachments = $message->attachments();

        // Простое письмо без второй части и вложений
        if ($attachments === [] && ($html === '' || $text === '')) {
            $single = $html !== '' ? $html : $text;

            return [
                'headers' => [
                    'Content-Type'              => ($html !== '' ? 'text/html' : 'text/plain') . '; charset=UTF-8',
                    'Content-Transfer-Encoding' => 'quoted-printable',
                ],
                'body' => quoted_printable_encode($single),
            ];
        }

        $alternative = 'alt-' . bin2hex(random_bytes(8));
        $mixed       = 'mix-' . bin2hex(random_bytes(8));

        $parts = '';

        if ($text !== '' && $html !== '') {
            $parts .= '--' . $alternative . "\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
                . quoted_printable_encode($text) . "\r\n"
                . '--' . $alternative . "\r\n"
                . "Content-Type: text/html; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
                . quoted_printable_encode($html) . "\r\n"
                . '--' . $alternative . "--\r\n";
        }

        if ($attachments === []) {
            return [
                'headers' => ['Content-Type' => 'multipart/alternative; boundary="' . $alternative . '"'],
                'body'    => $parts,
            ];
        }

        $body = '--' . $mixed . "\r\n";

        if ($parts !== '') {
            $body .= 'Content-Type: multipart/alternative; boundary="' . $alternative . "\"\r\n\r\n" . $parts;
        } else {
            $single = $html !== '' ? $html : $text;

            $body .= 'Content-Type: ' . ($html !== '' ? 'text/html' : 'text/plain') . "; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
                . quoted_printable_encode($single) . "\r\n";
        }

        foreach ($attachments as $file) {
            $body .= '--' . $mixed . "\r\n"
                . 'Content-Type: ' . $file['mime'] . '; name="' . self::encodeHeader($file['name']) . "\"\r\n"
                . "Content-Transfer-Encoding: base64\r\n"
                . 'Content-Disposition: attachment; filename="' . self::encodeHeader($file['name']) . "\"\r\n\r\n"
                . chunk_split(base64_encode($file['content']), 76, "\r\n");
        }

        $body .= '--' . $mixed . "--\r\n";

        return [
            'headers' => ['Content-Type' => 'multipart/mixed; boundary="' . $mixed . '"'],
            'body'    => $body,
        ];
    }

    /**
     * Письмо целиком: заголовки и тело одной строкой — так его принимает SMTP.
     */
    public static function build(Message $message): string
    {
        $body    = self::body($message);
        $headers = array_merge(self::headers($message), $body['headers']);

        $lines = '';

        foreach ($headers as $name => $value) {
            $lines .= $name . ': ' . $value . "\r\n";
        }

        return $lines . "\r\n" . $body['body'];
    }

    /**
     * «Имя <адрес>» с кодировкой имени.
     */
    public static function address(string $email, string $name = ''): string
    {
        return $name === '' ? $email : self::encodeHeader($name) . ' <' . $email . '>';
    }

    /**
     * Заголовок с русским текстом. Латиница остаётся как есть — так письмо
     * читаемо и в сыром виде.
     */
    public static function encodeHeader(string $value): string
    {
        $value = trim(str_replace(["\r", "\n"], ' ', $value));

        if ($value === '' || preg_match('/^[\x20-\x7E]*$/', $value) === 1) {
            return $value;
        }

        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    /**
     * Домен для Message-ID: свой, а не чужой — иначе письмо выглядит подделкой.
     */
    private static function hostname(string $fromEmail): string
    {
        $domain = substr(strrchr($fromEmail, '@') ?: '', 1);

        return $domain !== '' ? $domain : (string) (gethostname() ?: 'localhost');
    }
}
