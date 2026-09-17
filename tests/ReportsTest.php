<?php

declare(strict_types=1);

/**
 * Сводки отчётов.
 */

use App\Models\Note;
use Rsgrinko\Proton\Support\Reports;

test('отчёты: ряд по дням идёт без дырок', function (): void {
    withOwnDatabase(static function (): void {
        $today     = date('Y-m-d');
        $yesterday = date('Y-m-d', time() - 86400);

        Note::create(['title' => 'сегодня раз']);
        Note::create(['title' => 'сегодня два']);

        $old = Note::create(['title' => 'позавчера']);
        $old->forceFill(['created_at' => date('Y-m-d H:i:s', time() - 2 * 86400)])->save();

        $series = Reports::series(Note::query(), 'created_at', date('Y-m-d', time() - 2 * 86400), $today);

        assertCount(3, $series, 'три дня — три точки, даже если в середине пусто');
        assertSame(0, $series[$yesterday], 'пустой день в ряду нулём, а не пропуском');
        assertSame(2, $series[$today]);
        assertSame(3, Reports::total($series));
    });
});

test('отчёты: недели считаются по понедельникам', function (): void {
    withOwnDatabase(static function (): void {
        $note = Note::create(['title' => 'на прошлой неделе']);
        $note->forceFill(['created_at' => date('Y-m-d H:i:s', time() - 7 * 86400)])->save();

        $series = Reports::series(
            Note::query(),
            'created_at',
            date('Y-m-d', time() - 14 * 86400),
            date('Y-m-d'),
            Reports::WEEK
        );

        foreach (array_keys($series) as $key) {
            assertSame('Monday', date('l', (int) strtotime((string) $key)), 'ключ недели — дата понедельника');
        }

        assertSame(1, Reports::total($series));
    });
});

test('отчёты: сегодняшняя неделя не выпадает из ряда', function (): void {
    withOwnDatabase(static function (): void {
        // Баг ловился не всегда: пропадала последняя корзина, если день
        // недели $from в цикле «позже», чем день недели $to (шаг считался
        // от дня недели $from, а не от границы корзины)
        Note::create(['title' => 'сегодня']);

        $series = Reports::series(
            Note::query(),
            'created_at',
            date('Y-m-d', time() - 89 * 86400),
            date('Y-m-d'),
            Reports::WEEK
        );

        assertSame(1, Reports::total($series), 'запись за сегодня должна попасть в ряд недель');
    });
});

test('отчёты: текущий месяц не выпадает из ряда', function (): void {
    withOwnDatabase(static function (): void {
        Note::create(['title' => 'сегодня']);

        $series = Reports::series(
            Note::query(),
            'created_at',
            date('Y-m-d', time() - 364 * 86400),
            date('Y-m-d'),
            Reports::MONTH
        );

        assertSame(1, Reports::total($series), 'запись за сегодня должна попасть в ряд месяцев');
    });
});

test('отчёты: разбивка по колонке идёт от большего', function (): void {
    withOwnDatabase(static function (): void {
        Note::create(['title' => 'раз', 'pinned' => 1]);
        Note::create(['title' => 'два', 'pinned' => 0]);
        Note::create(['title' => 'три', 'pinned' => 0]);

        $breakdown = Reports::breakdown(Note::query(), 'pinned');

        assertSame([0 => 2, 1 => 1], array_map('intval', $breakdown));
        assertSame(2, array_values($breakdown)[0], 'первым идёт то, чего больше');
    });
});
