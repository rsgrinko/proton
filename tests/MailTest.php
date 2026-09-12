<?php

declare(strict_types=1);

/**
 * Почта: сборка письма, драйверы, очередь и подмена драйвера событием.
 */

use Rsgrinko\Proton\Events\Events;
use Rsgrinko\Proton\Mail\Drivers\DriverInterface;
use Rsgrinko\Proton\Mail\Mail;
use Rsgrinko\Proton\Mail\Message;
use Rsgrinko\Proton\Mail\Mime;
use Rsgrinko\Proton\Mail\SendMailJob;
use Rsgrinko\Proton\Queue\Queue;
use Rsgrinko\Proton\Support\ProtonException;

/**
 * Драйвер-запоминалка: складывает письма к себе, никуда не отправляя.
 */
final class MailTestDriver implements DriverInterface
{
    /** @var array<int, Message> */
    public array $messages = [];

    public function send(Message $message): void
    {
        $this->messages[] = $message;
    }

    public function name(): string
    {
        return 'test';
    }
}

test('письмо: собирается цепочкой и знает своих получателей', function (): void {
    $message = Message::to('user@example.com')
        ->copy('copy@example.com')
        ->from('robot@example.com', 'Робот')
        ->subject('Тема письма')
        ->html('<p>Привет</p>');

    assertCount(1, $message->recipients());
    assertCount(2, $message->envelopeRecipients(), 'копия тоже адресат конверта');
    assertSame('robot@example.com', $message->senderEmail());
    assertContains('Привет', $message->textBody(), 'текстовая часть считается по HTML');
});

test('письмо: неверный адрес отвергается сразу', function (): void {
    assertThrows(static function (): void {
        Message::to('не-адрес');
    });
});

test('письмо: перевод строки в заголовке не пройдёт', function (): void {
    // Иначе в письмо дописывается посторонний заголовок вроде Bcc
    $message = Message::to('user@example.com')->header('X-Custom', "значение\r\nBcc: chужой@example.com");

    assertNotContains("\n", $message->extraHeaders()['X-Custom']);
    assertNotContains('Bcc:', Mime::build($message->subject('т')->text('т')));

    assertThrows(static function (): void {
        Message::to('user@example.com')->header("X-Bad\r\nBcc", 'x');
    }, 'имя заголовка с переводом строки недопустимо');
});

test('письмо: русская тема кодируется, тело собирается в MIME', function (): void {
    $raw = Mime::build(
        Message::to('user@example.com')
            ->from('robot@example.com', 'Робот')
            ->subject('Привет, мир')
            ->html('<b>Здравствуйте</b>')
    );

    assertContains('=?UTF-8?B?', $raw, 'русские заголовки уходят закодированными');
    assertContains('MIME-Version: 1.0', $raw);
    assertContains('multipart/alternative', $raw, 'есть и текст, и HTML');
});

test('письмо: вложение попадает в MIME', function (): void {
    $raw = Mime::build(
        Message::to('user@example.com')
            ->from('robot@example.com')
            ->subject('С файлом')
            ->text('текст')
            ->attach('отчёт.csv', "a;b\n1;2", 'text/csv')
    );

    assertContains('multipart/mixed', $raw);
    assertContains('Content-Disposition: attachment', $raw);
});

test('почта: письмо уходит выбранным драйвером', function (): void {
    $driver = new MailTestDriver();

    Mail::setDriver($driver);

    try {
        Message::to('user@example.com')->from('robot@example.com')->subject('Проверка')->text('тело')->send();

        assertCount(1, $driver->messages);
        assertSame('Проверка', $driver->messages[0]->subjectLine());
    } finally {
        Mail::setDriver(null);
    }
});

test('почта: без отправителя письмо не уходит', function (): void {
    Mail::setDriver(new MailTestDriver());

    try {
        // Ни в письме, ни в настройках отправителя нет — молча отправлять нечем
        withConfig(['mail.from_email' => ''], static function (): void {
            $error = assertThrows(static function (): void {
                Message::to('user@example.com')->subject('без отправителя')->text('т')->send();
            });

            assertContains('отправитель', mb_strtolower($error->getMessage()));
        });
    } finally {
        Mail::setDriver(null);
    }
});

test('почта: событие может отменить отправку', function (): void {
    $driver = new MailTestDriver();

    Mail::setDriver($driver);

    Events::listen('mail.sending', static fn (): bool => false);

    try {
        $sent = Message::to('user@example.com')->from('robot@example.com')->subject('Отмена')->text('т')->send();

        assertFalse($sent, 'слушатель вернул false — письмо не уходит');
        assertCount(0, $driver->messages);
    } finally {
        Mail::setDriver(null);
        Events::reset();
    }
});

test('почта: письмо в очереди уходит задачей воркера', function (): void {
    withOwnDatabase(static function (): void {
        $driver = new MailTestDriver();

        Mail::setDriver($driver);

        try {
            $id = Message::to('user@example.com')
                ->from('robot@example.com')
                ->subject('Из очереди')
                ->text('тело')
                ->queue();

            assertTrue($id > 0);

            $row = assertNotNull(Rsgrinko\Proton\Database\Connection::instance()->selectOne('SELECT * FROM jobs WHERE id = :id', ['id' => $id]));

            assertSame(SendMailJob::class, (string) $row['job_class']);

            (new Rsgrinko\Proton\Queue\Worker())->run(true);

            assertCount(1, $driver->messages, 'воркер отправил письмо');
            assertSame('Из очереди', $driver->messages[0]->subjectLine());
            assertSame(0, Queue::stats()[Queue::QUEUED] ?? 0);
        } finally {
            Mail::setDriver(null);
        }
    });
});

test('почта: письмо переживает дорогу через очередь без потерь', function (): void {
    $original = Message::to('user@example.com')
        ->copy('copy@example.com')
        ->from('robot@example.com', 'Робот')
        ->subject('Туда и обратно')
        ->html('<p>тело</p>')
        ->header('X-Mark', 'метка')
        ->attach('file.txt', 'содержимое');

    $restored = Message::fromArray($original->toArray());

    assertSame($original->subjectLine(), $restored->subjectLine());
    assertSame($original->recipients(), $restored->recipients());
    assertSame($original->copies(), $restored->copies());
    assertSame('метка', $restored->extraHeaders()['X-Mark']);
    assertSame('содержимое', $restored->attachments()[0]['content']);
});
