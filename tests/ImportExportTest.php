<?php

declare(strict_types=1);

/**
 * Предпросмотр импорта, обновление по ключу и фоновая выгрузка.
 */

use App\Models\Note;
use Rsgrinko\Proton\Database\Model\Factory;
use Rsgrinko\Proton\Models\Role;
use Rsgrinko\Proton\Models\User;
use Rsgrinko\Proton\Models\UserNotification;
use Rsgrinko\Proton\Queue\Queue;
use Rsgrinko\Proton\Queue\Worker;
use Rsgrinko\Proton\Support\Csv;
use Rsgrinko\Proton\Support\ExportFile;
use Rsgrinko\Proton\Support\Exports;
use Rsgrinko\Proton\Support\Import;
use Rsgrinko\Proton\Support\ImportPlan;
use Rsgrinko\Proton\Support\SignedUrl;

/**
 * Колонки и правила демонстрационного импорта заметок.
 *
 * @return array{0: array<string, string>, 1: array<string, string>}
 */
function notesImportSpec(): array
{
    return [
        ['Название' => 'title', 'Текст' => 'body'],
        ['title' => 'required|max:191', 'body' => 'nullable|max:1000'],
    ];
}

test('предпросмотр: считает, что заведётся, что обновится и что отвалится', function (): void {
    withOwnDatabase(static function (): void {
        /** @var User $owner */
        $owner = Factory::of(User::class)->create();

        Note::create(['title' => 'Уже есть', 'body' => 'старый текст'])
            ->forceFill(['user_id' => $owner->id()])
            ->save();

        $content = Csv::write([
            ['Уже есть', 'новый текст'],
            ['Совсем новая', 'текст'],
            ['', 'без названия'],
        ], ['Название', 'Текст']);

        [$columns, $rules] = notesImportSpec();

        $plan = Import::plan($content, $columns, $rules, [], static fn (array $row): ?Note => Note::query()
            ->where('user_id', $owner->id())
            ->where('title', (string) $row['title'])
            ->first());

        assertSame(1, $plan->create);
        assertSame(1, $plan->update);
        assertSame(1, $plan->failed);
        assertSame(3, $plan->total());
        assertTrue($plan->any());
        assertCount(3, $plan->sample, 'в предпросмотре видно построчно');
        assertSame(ImportPlan::UPDATE, $plan->sample[0]['verdict']);
        assertSame(ImportPlan::SKIP, $plan->sample[2]['verdict']);

        // Предпросмотр ничего не пишет
        assertSame(1, Note::query()->count());
    });
});

test('импорт: повторная загрузка правит запись, а не двоит её', function (): void {
    withOwnDatabase(static function (): void {
        /** @var User $owner */
        $owner = Factory::of(User::class)->create();

        [$columns, $rules] = notesImportSpec();

        $locator = static fn (array $row): ?Note => Note::query()
            ->where('user_id', $owner->id())
            ->where('title', (string) $row['title'])
            ->first();

        $creator = static function (array $row) use ($owner): void {
            $note = new Note(['title' => (string) $row['title'], 'body' => (string) $row['body']]);

            $note->setAttribute('user_id', $owner->id());
            $note->save();
        };

        $updater = static function (Note $note, array $row): void {
            $note->fill(['body' => (string) $row['body']]);
            $note->save();
        };

        $content = Csv::write([['Отчёт', 'первая версия']], ['Название', 'Текст']);

        $first = Import::csv($content, $columns, $rules, $creator, [], $locator, $updater);

        assertSame(1, $first->loaded);
        assertSame(0, $first->updated);

        // Тот же файл со свежим текстом
        $again = Csv::write([['Отчёт', 'вторая версия']], ['Название', 'Текст']);

        $second = Import::csv($again, $columns, $rules, $creator, [], $locator, $updater);

        assertSame(0, $second->loaded);
        assertSame(1, $second->updated);
        assertSame(1, Note::query()->count(), 'запись одна');
        assertSame('вторая версия', (string) Note::query()->first()?->body);
        assertContains('обновлено: 1', $second->summary());
    });
});

test('выгрузка: слишком большая выборка уходит в очередь', function (): void {
    withOwnDatabase(static function (): void {
        /** @var User $admin */
        $admin = Factory::of(User::class)->create(['role_id' => Role::admin()?->id() ?? 0]);

        Factory::of(User::class)->times(4)->create();

        actingAs($admin);

        withConfig(['export.max_rows' => 2], static function () use ($admin): void {
            $response = get('/admin/users/export');

            assertStatus(302, $response);
            assertSame(1, Queue::stats()['queued'] ?? 0, 'задача поставлена');

            // Воркер собирает файл и уведомляет заказчика
            (new Worker())->run(true);

            $notification = UserNotification::query()
                ->where('user_id', $admin->id())
                ->where('type', 'export.ready')
                ->first();

            assertNotNull($notification, 'пришло уведомление о готовой выгрузке');

            $url  = (string) $notification->raw('url');
            $name = [];

            preg_match('~/exports/([^?]+)~', $url, $name);

            assertTrue(($name[1] ?? '') !== '', 'в ссылке есть имя файла');
            assertNotNull(ExportFile::path(urldecode($name[1])), 'файл лежит в var/exports');
        });

        actingAs(null);
    });
});

test('выгрузка: готовый файл отдаётся только по подписи', function (): void {
    withOwnDatabase(static function (): void {
        $name = ExportFile::save('users', Csv::write([['ivan', 'ivan@example.com']], ['Логин', 'Почта']));

        afterTests(static function () use ($name): void {
            $path = ExportFile::path($name);

            if ($path !== null) {
                @unlink($path);
            }
        });

        actingAs(null);

        assertStatus(403, get('/exports/' . $name), 'без подписи нельзя');

        $url = SignedUrl::to('exports.download', ['file' => $name], 3600);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $response = get('/exports/' . $name, $query);

        assertStatus(200, $response);
        assertContains('ivan@example.com', $response->body());

        // Чужое имя файла не подберёшь: путь проверяется
        assertNull(ExportFile::path('../../.env'));
        assertNull(ExportFile::path('нет-такого.csv'));
    });
});

test('выгрузка: старые файлы убираются', function (): void {
    $name = ExportFile::save('users', "тест\n");
    $path = (string) ExportFile::path($name);

    assertTrue(is_file($path));

    // Отматываем время файла назад
    touch($path, time() - 10 * 86400);

    assertTrue(ExportFile::purge(7) >= 1);
    assertNull(ExportFile::path($name));

    // Ноль дней — уборка выключена
    assertSame(0, ExportFile::purge(0));
});

test('реестр выгрузок: знает пользователей, журнал и заметки', function (): void {
    $kinds = Exports::all();

    assertTrue(isset($kinds['users'], $kinds['audit'], $kinds['notes']));
    assertSame('Пользователи', $kinds['users']['label']);
    assertNull(Exports::get('нет-такой'));
});
