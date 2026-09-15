<?php

declare(strict_types=1);

/**
 * Выгрузка и разбор CSV.
 */

use App\Models\Note;
use Rsgrinko\Proton\Support\Csv;
use Rsgrinko\Proton\Support\Export;
use Rsgrinko\Proton\Support\Import;

test('csv: файл открывается Excel и не выполняет формулы', function (): void {
    $content = Csv::write([['=2+2', 'обычное'], [true, null]], ['Первая', 'Вторая']);

    assertTrue(str_starts_with($content, Csv::BOM), 'без BOM кириллица в Excel рассыпается');
    assertContains('Первая;Вторая', $content);
    assertContains("'=2+2", $content, 'формула обезврежена');
    assertContains('да;', $content);
});

test('csv: разбор берёт заголовки из первой строки и угадывает разделитель', function (): void {
    $rows = Csv::read("логин,почта\r\nivan,ivan@example.com\r\n");

    assertCount(1, $rows);
    assertSame('ivan', $rows[0]['логин']);
    assertSame('ivan@example.com', $rows[0]['почта']);

    $rows = Csv::read(Csv::BOM . "логин;почта\nanna;anna@example.com\n\n");

    assertCount(1, $rows, 'пустая строка в конце файла не строка');
    assertSame('anna', $rows[0]['логин']);
});

test('выгрузка: колонки берут значение из модели и из своего замыкания', function (): void {
    withOwnDatabase(static function (): void {
        Note::create(['title' => 'export-a', 'body' => 'первое']);
        Note::create(['title' => 'export-b', 'body' => 'второе']);

        $query = Note::query()->whereLike('title', 'export-')->orderBy('title');

        $response = Export::csv($query, [
            'title' => 'Название',
            'длина' => ['Длина', static fn (Note $note): int => mb_strlen((string) $note->raw('body'))],
        ], 'notes');

        assertContains('Название;Длина', $response->body());
        assertContains('export-a;6', $response->body());
        assertContains('attachment; filename="notes-', $response->header('Content-Disposition'));
    });
});

test('выгрузка: предел строк считается до сборки файла', function (): void {
    withOwnDatabase(static function (): void {
        Note::create(['title' => 'limit-1']);
        Note::create(['title' => 'limit-2']);

        withConfig(['export.max_rows' => 1], static function (): void {
            assertFalse(Export::fits(Note::query()->whereLike('title', 'limit-')));
        });

        withConfig(['export.max_rows' => 10], static function (): void {
            assertTrue(Export::fits(Note::query()->whereLike('title', 'limit-')));
        });
    });
});

test('импорт: хорошие строки загружаются, плохие попадают в отчёт', function (): void {
    withOwnDatabase(static function (): void {
        $content = Csv::write([
            ['Первая', 'текст первой'],
            ['', 'без названия'],
            ['Вторая', 'текст второй'],
        ], ['Название', 'Текст']);

        $report = Import::csv($content, ['Название' => 'title', 'Текст' => 'body'], [
            'title' => 'required|max:191',
            'body'  => 'nullable|max:1000',
        ], static function (array $row): void {
            Note::create(['title' => (string) $row['title'], 'body' => (string) $row['body']]);
        });

        assertSame(2, $report->loaded);
        assertSame(1, $report->failed);
        assertFalse($report->ok());
        assertContains('Строка 3', $report->errors[0], 'номер строки — как в файле, вместе с заголовком');
        assertSame(2, Note::query()->count());
    });
});

test('импорт: файл без нужных колонок отвергается целиком', function (): void {
    $report = Import::csv(
        Csv::write([['что-то']], ['Чужая колонка']),
        ['Название' => 'title'],
        ['title' => 'required'],
        static function (): void {
            throw new RuntimeException('до строк дело дойти не должно');
        }
    );

    assertSame(0, $report->loaded);
    assertContains('нет колонок: Название', $report->errors[0]);
});

test('импорт: длинный файл не принимается за раз', function (): void {
    $rows = [];

    for ($number = 1; $number <= 5; $number++) {
        $rows[] = ['Заметка ' . $number];
    }

    withConfig(['import.max_rows' => 3], static function () use ($rows): void {
        $report = Import::csv(
            Csv::write($rows, ['Название']),
            ['Название' => 'title'],
            ['title' => 'required'],
            static function (): void {
            }
        );

        assertSame(0, $report->loaded);
        assertContains('больше, чем разрешено', $report->errors[0]);
    });
});

test('поиск: подчёркивание в строке ищется буквально, а не как любой символ', function (): void {
    withOwnDatabase(static function (): void {
        Note::create(['title' => 'qa_filters']);
        Note::create(['title' => 'qaXfilters']);

        $found = Note::query()->whereLike('title', 'qa_filters')->get();

        assertCount(1, $found, 'подчёркивание не должно работать как шаблон');
        assertSame('qa_filters', (string) $found[0]->title);

        // Процент тоже не шаблон
        Note::create(['title' => 'скидка 100%']);
        Note::create(['title' => 'скидка 1000 рублей']);

        assertSame(1, Note::query()->whereLike('title', '100%')->count());
    });
});
