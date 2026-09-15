<?php

declare(strict_types=1);

/**
 * Вложения, сироты в хранилище и профилировщик запросов.
 */

use App\Models\Note;
use Rsgrinko\Proton\Database\Model\Factory;
use Rsgrinko\Proton\Files\Attachment;
use Rsgrinko\Proton\Files\Storage;
use Rsgrinko\Proton\Files\UploadedFile;
use Rsgrinko\Proton\Models\User;
use Rsgrinko\Proton\Support\Profiler;

/**
 * Файл «как будто загруженный»: кладём содержимое во временный файл и отдаём
 * его UploadedFile — так же, как это делает PHP на настоящей загрузке.
 */
function fakeUpload(string $name, string $content = 'содержимое'): UploadedFile
{
    $tmp = tempnam(sys_get_temp_dir(), 'proton');

    file_put_contents((string) $tmp, $content);

    return new UploadedFile($name, (string) $tmp, strlen($content), UPLOAD_ERR_OK);
}

test('вложения: к записи прикладывается несколько файлов', function (): void {
    withOwnDatabase(static function (): void {
        /** @var User $owner */
        $owner = Factory::of(User::class)->create();
        /** @var Note $note */
        $note = Factory::of(Note::class)->create(['user_id' => $owner->id()]);

        $first  = Attachment::attach(fakeUpload('первый.txt'), 'note', $note->id(), $owner->id());
        $second = Attachment::attach(fakeUpload('второй.txt'), 'note', $note->id(), $owner->id());

        afterTests(static function () use ($first, $second): void {
            Storage::delete((string) $first->raw('path'));
            Storage::delete((string) $second->raw('path'));
        });

        $attachments = Attachment::of('note', $note->id());

        assertCount(2, $attachments);
        assertSame('второй.txt', (string) $attachments[0]->raw('name'), 'свежие сверху');
        assertTrue(Storage::exists((string) $first->raw('path')), 'файл лежит в хранилище');

        // Раскладка по годам и месяцам
        assertContains(date('Y/m'), (string) $first->raw('path'));

        // Картинки показываются превью, прочее — значком
        assertFalse($first->isImage());
        assertTrue(Attachment::attach(fakeUpload('снимок.png'), 'note', $note->id())->isImage());
    });
});

test('вложения: удаление убирает и запись, и файл', function (): void {
    withOwnDatabase(static function (): void {
        /** @var User $owner */
        $owner = Factory::of(User::class)->create();
        /** @var Note $note */
        $note = Factory::of(Note::class)->create(['user_id' => $owner->id()]);

        $attachment = Attachment::attach(fakeUpload('лишний.txt'), 'note', $note->id());
        $path       = (string) $attachment->raw('path');

        assertTrue(Storage::exists($path));

        $attachment->remove();

        assertFalse(Storage::exists($path), 'файл удалён');
        assertSame(0, Attachment::query()->count(), 'запись удалена');

        // Удаление всех вложений записи
        Attachment::attach(fakeUpload('раз.txt'), 'note', $note->id());
        Attachment::attach(fakeUpload('два.txt'), 'note', $note->id());

        assertSame(2, Attachment::detachAll('note', $note->id()));
        assertSame(0, Attachment::query()->count());
    });
});

test('вложения: файл без записи считается сиротой', function (): void {
    withOwnDatabase(static function (): void {
        /** @var User $owner */
        $owner = Factory::of(User::class)->create();
        /** @var Note $note */
        $note = Factory::of(Note::class)->create(['user_id' => $owner->id()]);

        $attachment = Attachment::attach(fakeUpload('нужный.txt'), 'note', $note->id());

        // Кладём файл мимо таблицы — так выглядит сорвавшаяся загрузка
        $orphanPath = 'note/' . date('Y/m') . '/сирота.txt';
        $full       = Storage::root() . '/' . $orphanPath;

        @mkdir(dirname($full), 0775, true);
        file_put_contents($full, 'ничей');

        afterTests(static function () use ($attachment, $orphanPath): void {
            Storage::delete((string) $attachment->raw('path'));
            Storage::delete($orphanPath);
        });

        $orphans = Attachment::orphans();

        assertTrue(in_array($orphanPath, $orphans, true), 'ничей файл нашёлся');
        assertFalse(in_array((string) $attachment->raw('path'), $orphans, true), 'нужный не тронут');
    });
});

test('запрос: несколько файлов одного поля приходят списком', function (): void {
    $request = Rsgrinko\Proton\Http\Request::create('POST', '/notes/new');

    $reflection = new ReflectionProperty($request, 'files');
    $reflection->setValue($request, [
        'attachment.0' => fakeUpload('раз.txt'),
        'attachment.1' => fakeUpload('два.txt'),
        'other'        => fakeUpload('третий.txt'),
    ]);

    assertCount(2, $request->files('attachment'));
    assertCount(1, $request->files('other'));
    assertCount(0, $request->files('нет-такого'));

    // Первый файл поля достаётся и старым способом
    assertNotNull($request->file('attachment'));
});

test('профилировщик: считает запросы и находит повторы', function (): void {
    withConfig(['app.debug' => true], static function (): void {
        Profiler::reset();

        // Один и тот же запрос несколько раз — это и есть N+1
        for ($number = 0; $number < 3; $number++) {
            User::query()->count();
        }

        assertTrue(Profiler::count() >= 3, 'запросы посчитаны');
        assertTrue(Profiler::time() >= 0);

        $repeats = Profiler::repeats(2);

        assertTrue($repeats !== [], 'повторы найдены');
        assertTrue(current($repeats) >= 3);

        assertCount(min(5, Profiler::count()), Profiler::slowest(5));

        Profiler::reset();

        assertSame(0, Profiler::count());
    });
});
