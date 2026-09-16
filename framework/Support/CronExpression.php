<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Support;

/**
 * Cron-выражение из пяти полей: минута час день месяц день-недели.
 *
 *     CronExpression::matches('*\/15 9-18 * * 1-5', time());   // рабочие часы буднего дня, каждые 15 минут
 *
 * Понимает `*`, число, список через запятую, диапазон `a-b` и шаг `*\/n` или
 * `a-b/n` — то, что реально встречается в расписании. Имён месяцев и дней
 * недели (`JAN`, `MON`) нет: числами короче и незачем тащить парсер сложнее
 * самого использования.
 */
final class CronExpression
{
    public static function matches(string $expression, int $timestamp): bool
    {
        return self::field(self::part($expression, 0), (int) date('i', $timestamp), 0, 59)
            && self::field(self::part($expression, 1), (int) date('G', $timestamp), 0, 23)
            && self::field(self::part($expression, 2), (int) date('j', $timestamp), 1, 31)
            && self::field(self::part($expression, 3), (int) date('n', $timestamp), 1, 12)
            && self::field(self::part($expression, 4), (int) date('w', $timestamp), 0, 6);
    }

    /**
     * Пять полей и только числа/`*`/`,`/`-`/`/` в них — иначе сообщение сразу
     * при регистрации задачи, а не молчаливое «никогда не наступит».
     */
    public static function valid(string $expression): bool
    {
        $fields = preg_split('/\s+/', trim($expression));

        if ($fields === false || count($fields) !== 5) {
            return false;
        }

        foreach ($fields as $field) {
            if (preg_match('/^(\*|\d+(-\d+)?)(\/\d+)?(,(\*|\d+(-\d+)?)(\/\d+)?)*$/', $field) !== 1) {
                return false;
            }
        }

        return true;
    }

    private static function part(string $expression, int $index): string
    {
        $fields = preg_split('/\s+/', trim($expression));

        return (string) ($fields[$index] ?? '*');
    }

    private static function field(string $field, int $value, int $min, int $max): bool
    {
        foreach (explode(',', $field) as $part) {
            if (self::partMatches($part, $value, $min, $max)) {
                return true;
            }
        }

        return false;
    }

    private static function partMatches(string $part, int $value, int $min, int $max): bool
    {
        $step = 1;

        if (str_contains($part, '/')) {
            [$part, $stepText] = explode('/', $part, 2);
            $step = max(1, (int) $stepText);
        }

        if ($part === '*') {
            $from = $min;
            $to   = $max;
        } elseif (str_contains($part, '-')) {
            [$fromText, $toText] = explode('-', $part, 2);
            $from = (int) $fromText;
            $to   = (int) $toText;
        } else {
            $from = $to = (int) $part;
        }

        if ($value < $from || $value > $to) {
            return false;
        }

        return ($value - $from) % $step === 0;
    }
}
