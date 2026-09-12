<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Mail;

use Rsgrinko\Proton\Support\Config;
use Rsgrinko\Proton\Support\ProtonException;
use Rsgrinko\Proton\Support\Str;
use Rsgrinko\Proton\Support\Validator;
use Rsgrinko\Proton\View\View;

/**
 * Письмо. Собирается цепочкой и уходит в Mail::send().
 *
 *     Message::to('user@example.com')
 *         ->subject('Подтверждение почты')
 *         ->view('mail/verify', ['link' => $link])
 *         ->send();
 */
final class Message
{
    /** @var array<int, string> */
    private array $to = [];

    /** @var array<int, string> */
    private array $cc = [];

    /** @var array<int, string> */
    private array $bcc = [];

    private string $fromEmail = '';

    private string $fromName = '';

    private string $replyTo = '';

    private string $subject = '';

    private string $text = '';

    private string $html = '';

    /** @var array<string, string> Дополнительные заголовки */
    private array $headers = [];

    /** @var array<int, array{name: string, content: string, mime: string}> */
    private array $attachments = [];

    public static function to(string ...$addresses): self
    {
        $message = new self();

        foreach ($addresses as $address) {
            $message->addTo($address);
        }

        return $message;
    }

    public function addTo(string $address): self
    {
        $address = trim($address);

        if (!Validator::isEmail($address)) {
            throw new ProtonException('Неверный адрес получателя: ' . $address);
        }

        $this->to[] = $address;

        return $this;
    }

    public function copy(string $address): self
    {
        $this->cc[] = trim($address);

        return $this;
    }

    public function blindCopy(string $address): self
    {
        $this->bcc[] = trim($address);

        return $this;
    }

    public function from(string $email, string $name = ''): self
    {
        $this->fromEmail = trim($email);
        $this->fromName  = trim($name);

        return $this;
    }

    public function replyTo(string $email): self
    {
        $this->replyTo = trim($email);

        return $this;
    }

    public function subject(string $subject): self
    {
        $this->subject = trim($subject);

        return $this;
    }

    public function text(string $text): self
    {
        $this->text = $text;

        return $this;
    }

    public function html(string $html): self
    {
        $this->html = $html;

        // Текстовую часть считаем сама, если её не задали: письмо без text
        // некоторые клиенты и спам-фильтры любят меньше
        if ($this->text === '') {
            $this->text = Str::htmlToText($html);
        }

        return $this;
    }

    /**
     * Тело письма из шаблона resources/views.
     *
     * @param array<string, mixed> $data
     */
    public function view(string $template, array $data = []): self
    {
        return $this->html(View::partial($template, $data));
    }

    public function header(string $name, string $value): self
    {
        // Перевод строки в заголовке дописывает в письмо посторонние заголовки —
        // отсюда и рассылка мимо стоп-листа, и подделка отправителя
        if (preg_match('/^[A-Za-z0-9-]+$/', $name) !== 1) {
            throw new ProtonException('Недопустимое имя заголовка: ' . $name);
        }

        $this->headers[$name] = trim(str_replace(["\r", "\n"], '', $value));

        return $this;
    }

    /**
     * Вложение из строки в памяти.
     */
    public function attach(string $fileName, string $content, string $mime = 'application/octet-stream'): self
    {
        $this->attachments[] = ['name' => $fileName, 'content' => $content, 'mime' => $mime];

        return $this;
    }

    /**
     * Вложение из файла на диске.
     */
    public function attachFile(string $path, string $fileName = ''): self
    {
        if (!is_file($path)) {
            throw new ProtonException('Файл вложения не найден: ' . $path);
        }

        return $this->attach(
            $fileName !== '' ? $fileName : basename($path),
            (string) file_get_contents($path)
        );
    }

    /**
     * Отправить прямо сейчас.
     */
    public function send(): bool
    {
        return Mail::send($this);
    }

    /**
     * Поставить в очередь — письмо уйдёт воркером, а страница не будет ждать SMTP.
     */
    public function queue(int $delaySeconds = 0): int
    {
        return Mail::queue($this, $delaySeconds);
    }

    // --- Чтение --------------------------------------------------------------

    /**
     * @return array<int, string>
     */
    public function recipients(): array
    {
        return $this->to;
    }

    /**
     * @return array<int, string>
     */
    public function copies(): array
    {
        return $this->cc;
    }

    /**
     * @return array<int, string>
     */
    public function blindCopies(): array
    {
        return $this->bcc;
    }

    /**
     * Все адреса конверта — им и доставляем.
     *
     * @return array<int, string>
     */
    public function envelopeRecipients(): array
    {
        return array_values(array_unique(array_merge($this->to, $this->cc, $this->bcc)));
    }

    public function senderEmail(): string
    {
        return $this->fromEmail !== '' ? $this->fromEmail : (string) Config::get('mail.from_email', '');
    }

    public function senderName(): string
    {
        return $this->fromName !== '' ? $this->fromName : (string) Config::get('mail.from_name', '');
    }

    public function replyAddress(): string
    {
        return $this->replyTo;
    }

    public function subjectLine(): string
    {
        return $this->subject;
    }

    public function textBody(): string
    {
        return $this->text;
    }

    public function htmlBody(): string
    {
        return $this->html;
    }

    /**
     * @return array<string, string>
     */
    public function extraHeaders(): array
    {
        return $this->headers;
    }

    /**
     * @return array<int, array{name: string, content: string, mime: string}>
     */
    public function attachments(): array
    {
        return $this->attachments;
    }

    /**
     * Письмо в виде массива — так его кладут в очередь и отправляют по API.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'to'          => $this->to,
            'cc'          => $this->cc,
            'bcc'         => $this->bcc,
            'from'        => $this->senderEmail(),
            'from_name'   => $this->senderName(),
            'reply_to'    => $this->replyTo,
            'subject'     => $this->subject,
            'text'        => $this->text,
            'html'        => $this->html,
            'headers'     => $this->headers,
            'attachments' => array_map(
                static fn (array $file): array => [
                    'name'    => $file['name'],
                    'mime'    => $file['mime'],
                    'content' => base64_encode($file['content']),
                ],
                $this->attachments
            ),
        ];
    }

    /**
     * Обратная сборка — из очереди письмо приходит массивом.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $message = new self();

        foreach ((array) ($data['to'] ?? []) as $address) {
            $message->addTo((string) $address);
        }

        foreach ((array) ($data['cc'] ?? []) as $address) {
            $message->copy((string) $address);
        }

        foreach ((array) ($data['bcc'] ?? []) as $address) {
            $message->blindCopy((string) $address);
        }

        $message->from((string) ($data['from'] ?? ''), (string) ($data['from_name'] ?? ''));
        $message->subject((string) ($data['subject'] ?? ''));
        $message->text((string) ($data['text'] ?? ''));

        if (($data['html'] ?? '') !== '') {
            $message->html((string) $data['html']);
        }

        if (($data['reply_to'] ?? '') !== '') {
            $message->replyTo((string) $data['reply_to']);
        }

        foreach ((array) ($data['headers'] ?? []) as $name => $value) {
            $message->header((string) $name, (string) $value);
        }

        foreach ((array) ($data['attachments'] ?? []) as $file) {
            $message->attach(
                (string) ($file['name'] ?? 'file'),
                (string) base64_decode((string) ($file['content'] ?? ''), true),
                (string) ($file['mime'] ?? 'application/octet-stream')
            );
        }

        return $message;
    }
}
