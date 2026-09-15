<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Support;

/**
 * Чтение и запись CSV.
 *
 * Разделитель по умолчанию — точка с запятой, а в начале файла BOM: Excel с
 * русской локалью иначе показывает запятые как один столбец и ломает кириллицу.
 * Для машин это ничего не меняет — разделитель настраивается (`export.delimiter`).
 */
final class Csv
{
    public const BOM = "\xEF\xBB\xBF";

    /**
     * Строки в текст CSV.
     *
     * @param iterable<int, array<int|string, mixed>> $rows
     * @param array<int, string>                      $headers
     */
    public static function write(iterable $rows, array $headers = [], string $delimiter = ''): string
    {
        $delimiter = $delimiter !== '' ? $delimiter : self::delimiter();
        $handle    = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new ProtonException('Не удалось открыть временный поток для CSV');
        }

        if ($headers !== []) {
            fputcsv($handle, $headers, $delimiter, '"', '\\');
        }

        foreach ($rows as $row) {
            fputcsv($handle, array_map(self::cell(...), $row), $delimiter, '"', '\\');
        }

        rewind($handle);

        $content = (string) stream_get_contents($handle);

        fclose($handle);

        return self::BOM . $content;
    }

    /**
     * Разбор CSV в строки. Первая строка — заголовки, они же ключи каждой
     * строки: так импорт не зависит от порядка колонок в файле.
     *
     * @return array<int, array<string, string>>
     */
    public static function read(string $content, string $delimiter = ''): array
    {
        $content = str_starts_with($content, self::BOM) ? substr($content, strlen(self::BOM)) : $content;

        // Файл мог приехать из Windows-редактора или с макбука
        $content = str_replace(["\r\n", "\r"], "\n", $content);

        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new ProtonException('Не удалось открыть временный поток для CSV');
        }

        fwrite($handle, $content);
        rewind($handle);

        $delimiter = $delimiter !== '' ? $delimiter : self::sniff($content);
        $headers   = [];
        $rows      = [];

        while (($line = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
            // Пустая строка в конце файла — обычное дело
            if ($line === [null] || $line === ['']) {
                continue;
            }

            $line = array_map(static fn (mixed $value): string => trim((string) $value), $line);

            if ($headers === []) {
                $headers = $line;

                continue;
            }

            $row = [];

            foreach ($headers as $index => $header) {
                $row[$header] = $line[$index] ?? '';
            }

            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }

    /**
     * Разделитель файла: что чаще встречается в первой строке, то и разделитель.
     * Люди приносят файлы и из Excel, и из выгрузок с запятой.
     */
    private static function sniff(string $content): string
    {
        $first = strtok($content, "\n");

        if ($first === false) {
            return self::delimiter();
        }

        return substr_count($first, ';') >= substr_count($first, ',') ? ';' : ',';
    }

    private static function delimiter(): string
    {
        $delimiter = (string) Config::get('export.delimiter', ';');

        return $delimiter !== '' ? $delimiter : ';';
    }

    /**
     * Значение ячейки. Формулы обезвреживаем: строка, начинающаяся со знака
     * равенства, в Excel выполнится, а файл может уехать кому угодно.
     */
    private static function cell(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'да' : 'нет';
        }

        $text = $value === null ? '' : (string) $value;

        return preg_match('/^[=+\-@]/', $text) === 1 ? "'" . $text : $text;
    }
}
