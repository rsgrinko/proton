<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Support;

use Rsgrinko\Proton\Database\Model\Model;
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
 *
 * Повторную загрузку того же файла спасает режим обновления: `locator` ищет
 * запись по ключу, и найденная не создаётся заново, а правится (`updater`).
 * Без него повторный файл просто задвоил бы данные.
 *
 * Перед загрузкой есть смысл посмотреть, что получится: `plan()` считает то же
 * самое, но ничего не пишет.
 */
final class Import
{
    /**
     * Загрузка файла.
     *
     * @param array<string, string> $columns заголовок в файле => поле
     * @param array<string, string> $rules   правила проверки полей
     * @param callable(array<string, mixed>, int): void $handler что делать с новой строкой
     * @param array<string, string> $labels  подписи полей для сообщений об ошибках
     * @param callable(array<string, mixed>): ?Model $locator как найти уже существующую запись
     * @param callable(Model, array<string, mixed>): void $updater что делать с найденной
     */
    public static function csv(
        string $content,
        array $columns,
        array $rules,
        callable $handler,
        array $labels = [],
        ?callable $locator = null,
        ?callable $updater = null
    ): ImportReport {
        $prepared = self::prepare($content, $columns, $labels);

        if (is_string($prepared)) {
            return new ImportReport(0, 0, [$prepared]);
        }

        [$rows, $labels] = $prepared;

        $loaded  = 0;
        $updated = 0;
        $failed  = 0;
        $errors  = [];

        foreach ($rows as $index => $row) {
            $line = $index + 2;
            $data = self::map($row, $columns);

            $error = self::check($data, $rules, $labels);

            if ($error !== '') {
                $failed++;
                $errors[] = 'Строка ' . $line . ': ' . $error;

                continue;
            }

            try {
                $existing = $locator !== null ? $locator($data) : null;

                if ($existing instanceof Model && $updater !== null) {
                    $updater($existing, $data);

                    $updated++;

                    continue;
                }

                $handler($data, $line);

                $loaded++;
            } catch (Throwable $e) {
                $failed++;
                $errors[] = 'Строка ' . $line . ': ' . $e->getMessage();
            }
        }

        return new ImportReport($loaded, $failed, $errors, $updated);
    }

    /**
     * Что будет, если загрузить этот файл: ничего не пишется, только считается.
     *
     * @param array<string, string> $columns
     * @param array<string, string> $rules
     * @param array<string, string> $labels
     * @param callable(array<string, mixed>): ?Model $locator
     */
    public static function plan(
        string $content,
        array $columns,
        array $rules,
        array $labels = [],
        ?callable $locator = null,
        int $show = 20
    ): ImportPlan {
        $prepared = self::prepare($content, $columns, $labels);

        if (is_string($prepared)) {
            return new ImportPlan(0, 0, 0, [], [$prepared]);
        }

        [$rows, $labels] = $prepared;

        $create = 0;
        $update = 0;
        $failed = 0;
        $sample = [];
        $errors = [];

        foreach ($rows as $index => $row) {
            $line = $index + 2;
            $data = self::map($row, $columns);

            $error = self::check($data, $rules, $labels);

            if ($error !== '') {
                $failed++;
                $errors[] = 'Строка ' . $line . ': ' . $error;
                $verdict  = ImportPlan::SKIP;
            } elseif ($locator !== null && $locator($data) instanceof Model) {
                $update++;
                $verdict = ImportPlan::UPDATE;
            } else {
                $create++;
                $verdict = ImportPlan::CREATE;
            }

            if (count($sample) < max(1, $show)) {
                $sample[] = ['line' => $line, 'verdict' => $verdict, 'data' => $data, 'error' => $error];
            }
        }

        return new ImportPlan($create, $update, $failed, $sample, $errors);
    }

    /**
     * Общая подготовка: разбор файла и проверки, после которых читать нечего.
     *
     * @param array<string, string> $columns
     * @param array<string, string> $labels
     *
     * @return string|array{0: array<int, array<string, string>>, 1: array<string, string>}
     */
    private static function prepare(string $content, array $columns, array $labels): string|array
    {
        // Без подписей человек читал бы «поле title» — подставляем заголовки файла
        if ($labels === []) {
            $labels = array_flip($columns);
        }

        $rows = Csv::read($content);

        if ($rows === []) {
            return 'В файле нет ни одной строки с данными';
        }

        $limit = max(1, (int) Config::get('import.max_rows', 5000));

        if (count($rows) > $limit) {
            return 'Строк в файле больше, чем разрешено за раз: ' . $limit;
        }

        $missing = self::missing($rows[0], $columns);

        if ($missing !== []) {
            return 'В файле нет колонок: ' . implode(', ', $missing);
        }

        return [$rows, $labels];
    }

    /**
     * Строка файла в поля.
     *
     * @param array<string, string> $row
     * @param array<string, string> $columns
     *
     * @return array<string, mixed>
     */
    private static function map(array $row, array $columns): array
    {
        $data = [];

        foreach ($columns as $header => $field) {
            $data[$field] = $row[$header] ?? '';
        }

        return $data;
    }

    /**
     * Первая ошибка строки или пустая строка, если всё в порядке.
     *
     * @param array<string, mixed>  $data
     * @param array<string, string> $rules
     * @param array<string, string> $labels
     */
    private static function check(array $data, array $rules, array $labels): string
    {
        $validator = Validator::make($data, $rules, $labels);

        return $validator->passes() ? '' : self::first($validator->errors());
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
