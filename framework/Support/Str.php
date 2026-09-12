<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Support;

/**
 * Строковые помощники, нужные в разных местах приложения.
 */
final class Str
{
    /**
     * UUID версии 4.
     */
    public static function uuid(): string
    {
        $bytes = random_bytes(16);

        // Версия (4) и вариант (10xx) по стандарту
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }

    /**
     * Случайная строка из алфавита без похожих символов (0/O, 1/l).
     */
    public static function random(int $length = 32): string
    {
        $alphabet = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $max      = strlen($alphabet) - 1;
        $result   = '';

        for ($i = 0; $i < $length; $i++) {
            $result .= $alphabet[random_int(0, $max)];
        }

        return $result;
    }

    /**
     * Адрес из строки: «Заголовок статьи» -> «zagolovok-stati».
     */
    public static function slug(string $value): string
    {
        // Битую кодировку чиним, а не молчим: иначе preg с флагом /u ничего
        // не находит и адресная часть выходит пустой — а виновата не строка,
        // а клиент, приславший не UTF-8
        if (!mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'Windows-1251, ISO-8859-1');
        }

        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = strtr($value, self::TRANSLIT);
        $value = (string) preg_replace('/[^a-z0-9]+/u', '-', $value);

        return trim($value, '-');
    }

    /**
     * snake_case из CamelCase: MessageEvent -> message_event.
     */
    public static function snake(string $value): string
    {
        $value = (string) preg_replace('/(?<!^)[A-Z]/', '_$0', $value);

        return strtolower($value);
    }

    /**
     * CamelCase из snake_case: message_event -> MessageEvent.
     */
    public static function studly(string $value): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $value)));
    }

    /**
     * Множественное число для имени таблицы: user -> users, category -> categories.
     * Правила простые, английские: имя таблицы всегда можно задать руками.
     */
    public static function plural(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        $last = substr($value, -1);

        if ($last === 'y' && !in_array(substr($value, -2, 1), ['a', 'e', 'i', 'o', 'u'], true)) {
            return substr($value, 0, -1) . 'ies';
        }

        if (in_array(substr($value, -2), ['ch', 'sh', 'ss'], true) || in_array($last, ['s', 'x', 'z'], true)) {
            return $value . 'es';
        }

        return $value . 's';
    }

    /**
     * Обрезка с многоточием.
     */
    public static function limit(string $value, int $limit = 100): string
    {
        if (mb_strlen($value, 'UTF-8') <= $limit) {
            return $value;
        }

        return mb_substr($value, 0, $limit - 1, 'UTF-8') . '…';
    }

    /**
     * Человекочитаемый размер файла.
     */
    public static function bytes(int $size): string
    {
        $units = ['Б', 'КБ', 'МБ', 'ГБ', 'ТБ'];
        $index = 0;
        $value = (float) $size;

        while ($value >= 1024 && $index < count($units) - 1) {
            $value /= 1024;
            $index++;
        }

        return ($index === 0 ? (string) (int) $value : number_format($value, 1, ',', ' ')) . ' ' . $units[$index];
    }

    /**
     * Грубое превращение HTML в текст — для писем, где есть только HTML.
     */
    public static function htmlToText(string $html): string
    {
        $text = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;
        $text = preg_replace('#<br\s*/?>#i', "\n", $text) ?? $text;
        $text = preg_replace('#</(p|div|tr|h[1-6]|li)>#i', "\n", $text) ?? $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+/", ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * Сравнение без утечки времени — для ключей, токенов и подписей.
     */
    public static function secureEquals(string $known, string $given): bool
    {
        return hash_equals($known, $given);
    }

    /** @var array<string, string> Кириллица для slug() */
    private const TRANSLIT = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e',
        'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm',
        'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u',
        'ф' => 'f', 'х' => 'h', 'ц' => 'c', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch', 'ъ' => '',
        'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
    ];
}
