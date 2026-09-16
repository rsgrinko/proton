<?php

declare(strict_types=1);

/**
 * Cron-выражение: пять полей, *, списки, диапазоны и шаг.
 */

use Rsgrinko\Proton\Support\CronExpression;

test('cron: звёздочка и точное значение', function (): void {
    // Понедельник 10 марта 2025, 09:30
    $timestamp = mktime(9, 30, 0, 3, 10, 2025);

    assertTrue(CronExpression::matches('* * * * *', $timestamp));
    assertTrue(CronExpression::matches('30 9 10 3 1', $timestamp), 'все поля совпадают');
    assertFalse(CronExpression::matches('31 9 10 3 1', $timestamp), 'минута не совпадает');
    assertFalse(CronExpression::matches('30 10 * * *', $timestamp), 'час не совпадает');
});

test('cron: список через запятую', function (): void {
    $timestamp = mktime(9, 15, 0, 3, 10, 2025);

    assertTrue(CronExpression::matches('0,15,30,45 * * * *', $timestamp));
    assertFalse(CronExpression::matches('0,30,45 * * * *', $timestamp));
});

test('cron: диапазон и диапазон с шагом', function (): void {
    $inRange    = mktime(12, 0, 0, 3, 10, 2025);
    $outOfRange = mktime(20, 0, 0, 3, 10, 2025);

    assertTrue(CronExpression::matches('0 9-18 * * *', $inRange));
    assertFalse(CronExpression::matches('0 9-18 * * *', $outOfRange));

    // Каждые 15 минут: 0, 15, 30, 45 — но не 20
    assertTrue(CronExpression::matches('*/15 * * * *', mktime(9, 0, 0, 3, 10, 2025)));
    assertTrue(CronExpression::matches('*/15 * * * *', mktime(9, 45, 0, 3, 10, 2025)));
    assertFalse(CronExpression::matches('*/15 * * * *', mktime(9, 20, 0, 3, 10, 2025)));
});

test('cron: день недели — рабочие дни против выходных', function (): void {
    $monday   = mktime(9, 0, 0, 3, 10, 2025);   // понедельник
    $saturday = mktime(9, 0, 0, 3, 15, 2025);   // суббота

    assertTrue(CronExpression::matches('0 9 * * 1-5', $monday));
    assertFalse(CronExpression::matches('0 9 * * 1-5', $saturday));
});

test('cron: проверка формата — пять полей и только числа/*/,/-//', function (): void {
    assertTrue(CronExpression::valid('*/15 9-18 * * 1-5'));
    assertTrue(CronExpression::valid('0,30 * * * *'));
    assertFalse(CronExpression::valid('* * * *'), 'четыре поля вместо пяти');
    assertFalse(CronExpression::valid('* * * * * *'), 'шесть полей вместо пяти');
    assertFalse(CronExpression::valid('пора * * * *'), 'не число и не *');
    assertFalse(CronExpression::valid('MON * * * *'), 'имена дней недели не поддержаны');
});
