<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Support;

/**
 * Сквозной идентификатор запроса.
 *
 * Клиент присылает его заголовком X-Request-Id, а если не прислал — заводим свой.
 * Дальше он сам подставляется в каждую строку лога и возвращается в ответе, и
 * разбор жалобы «у меня не сработало вчера в 14:20» перестаёт быть археологией.
 */
final class RequestId
{
    public const HEADER = 'X-Request-Id';

    private static string $current = '';

    /**
     * Ставит идентификатор на весь запрос. Чужую строку чистим: она уедет
     * в логи и в заголовки ответа, и мусор оттуда потом не вычистить.
     */
    public static function set(string $value = ''): string
    {
        $value = trim($value);
        $value = (string) preg_replace('/[^A-Za-z0-9._-]/', '', $value);
        $value = substr($value, 0, 64);

        return self::$current = $value !== '' ? $value : self::generate();
    }

    /**
     * Текущий идентификатор. Нет — заводим сейчас: воркеру и консоли он тоже нужен.
     */
    public static function current(): string
    {
        if (self::$current === '') {
            self::$current = self::generate();
        }

        return self::$current;
    }

    public static function has(): bool
    {
        return self::$current !== '';
    }

    /**
     * Сбрасывает идентификатор — воркер зовёт это перед каждой задачей,
     * чтобы соседние задачи не сливались в одну цепочку.
     */
    public static function reset(): void
    {
        self::$current = '';
    }

    private static function generate(): string
    {
        return bin2hex(random_bytes(8));
    }
}
