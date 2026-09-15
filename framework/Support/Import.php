<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Support;

use Throwable;

/**
 * Разбор загруженного CSV построчно: колонки файла переводятся в поля, каждая
 * строка проходит `Validator`, и только потом её получает обработчик.
 *
 *     $report = Import::csv($content, [
 *         'Название' => 'title',
 *         'Текст'    => 'body',
 *     ], [
 *         'title' => 'required|max:191',
 *         'body'  => 'nullable|max:5000',
 *     ], static function (array $row): void {
 *         Note::create($row);
 *     });
 *
 * Одна плохая строка не отменяет весь файл: она попадает в отчёт с номером,
 * остальные загружаются. Номер строки — как в Excel, вместе с заголовком,
 * иначе искать ошибку в файле неудобно.
 */
final class Import
{
    /**
     * @param array<string, string> $columns заголовок в файле => поле
     * @param array<string, string> $rules   правила проверки полей
     * @param callable(array<string, mixed>, int): void $handler что делать со строкой
     * @param array<string, string> $labels  подписи полей для сообщений об ошибках
     */
    public static function csv(string $content, array $columns, array $rules, callable $handler, array $labels = []): ImportReport
    {
        // Без подписей человек читал бы «поле title» — подставляем заголовки файла
        if ($labels === []) {
            $labels = array_flip($columns);
        }

        $rows = Csv::read($content);

        if ($rows === []) {
            return new ImportReport(0, 0, ['В файле нет ни одной строки с данными']);
        }

        $limit = max(1, (int) Config::get('import.max_rows', 5000));

        if (count($rows) > $limit) {
            return new ImportReport(0, 0, ['Строк в файле больше, чем разрешено за раз: ' . $limit]);
        }

        $missing = self::missing($rows[0], $columns);

        if ($missing !== []) {
            return new ImportReport(0, 0, ['В файле нет колонок: ' . implode(', ', $missing)]);
        }

        $loaded = 0;
        $failed = 0;
        $errors = [];

        foreach ($rows as $index => $row) {
            // Первая строка файла — заголовки, поэтому данные начинаются со второй
            $line = $index + 2;

            $data = [];

            foreach ($columns as $header => $field) {
                $data[$field] = $row[$header] ?? '';
            }

            $validator = Validator::make($data, $rules, $labels);

            if (!$validator->passes()) {
                $failed++;
                $errors[] = 'Строка ' . $line . ': ' . self::first($validator->errors());

                continue;
            }

            try {
                $handler($data, $line);

                $loaded++;
            } catch (Throwable $e) {
                $failed++;
                $errors[] = 'Строка ' . $line . ': ' . $e->getMessage();
            }
        }

        return new ImportReport($loaded, $failed, $errors);
    }

    /**
     * Каких колонок не хватает в файле.
     *
     * @param array<string, string> $row
     * @param array<string, string> $columns
     *
     * @return array<int, string>
     */
    private static function missing(array $row, array $columns): array
    {
        $missing = [];

        foreach (array_keys($columns) as $header) {
            if (!array_key_exists($header, $row)) {
                $missing[] = $header;
            }
        }

        return $missing;
    }

    /**
     * @param array<string, array<int, string>> $errors
     */
    private static function first(array $errors): string
    {
        foreach ($errors as $messages) {
            foreach ($messages as $message) {
                return $message;
            }
        }

        return 'строка не прошла проверку';
    }
}
