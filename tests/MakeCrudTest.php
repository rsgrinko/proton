<?php

declare(strict_types=1);

/**
 * Генератор раздела: файлы существуют, читаются и остаются рабочим PHP.
 *
 * Проверяет только форму: раскладку по файлам и то, что получившийся код
 * компилируется. Само поведение сгенерированного раздела (список, форма,
 * права) — не отсюда, это уже код приложения, а не генератора.
 */

use Rsgrinko\Proton\Console\Commands\MakeCrudCommand;

/**
 * Гонит команду и ловит имена созданных файлов из её же вывода «Создано: …» —
 * так не нужно гадать, как Str::plural() склоняет придуманное имя.
 *
 * @return array{status: int, created: array<int, string>}
 */
function runMakeCrud(array $args, array $options): array
{
    $created = [];

    $status = (new MakeCrudCommand())->withInput($args, $options, static function (string $line) use (&$created): void {
        $line = trim($line);

        if (str_starts_with($line, 'Создано: ')) {
            $created[] = mb_substr($line, mb_strlen('Создано: '));
        }
    })->run();

    return ['status' => $status, 'created' => $created];
}

/**
 * Убирает всё, что команда создала, — генератор пишет в настоящие каталоги
 * приложения, а не в песочницу. Каталог вьюх (resources/views/{раздел}/)
 * генератор заводит сам — за собой его тоже нужно убрать, иначе после каждого
 * прогона тестов остаются пустые zz_crud_* папки.
 *
 * @param array<int, string> $created
 */
function cleanupCrud(array $created): void
{
    $dirs = [];

    foreach ($created as $relative) {
        $path = APP_ROOT . '/' . $relative;

        @unlink($path);

        $dirs[dirname($path)] = true;
    }

    foreach (array_keys($dirs) as $dir) {
        @rmdir($dir);
    }
}

test('make:crud: без --api создаёт только веб-раздел', function (): void {
    $result = runMakeCrud(['ZzCrudPlain'], ['fields' => 'title:string:Название']);

    try {
        assertSame(0, $result['status']);
        assertCount(7, $result['created'], 'модель, контроллер, миграция, три вьюхи и тест');

        foreach ($result['created'] as $path) {
            assertNotContains('Controllers/Api', $path, 'без --api никакого API-контроллера');
        }
    } finally {
        cleanupCrud($result['created']);
    }
});

test('make:crud: --api добавляет контроллер под ключ, и он рабочий PHP', function (): void {
    $result = runMakeCrud(['ZzCrudSmoke'], [
        'fields' => 'title:string:Название,total:decimal:Сумма',
        'api'    => '1',
    ]);

    try {
        assertSame(0, $result['status']);

        $apiPath = APP_ROOT . '/app/Controllers/Api/ZzCrudSmokesController.php';

        assertTrue(in_array('app/Controllers/Api/ZzCrudSmokesController.php', $result['created'], true));
        assertTrue(is_file($apiPath));

        $code = (string) file_get_contents($apiPath);

        assertContains('namespace App\Controllers\Api;', $code);
        assertContains('extends ApiController', $code);
        assertContains('function index(', $code);
        assertContains('function show(', $code);
        assertContains('function store(', $code);
        assertContains('function update(', $code);
        assertContains('function delete(', $code);
        assertContains("whereLike('title'", $code, 'поиск идёт по первому полю');

        // Не просто похоже на PHP — компилируется по-настоящему
        exec('php -l ' . escapeshellarg($apiPath) . ' 2>&1', $lint, $exitCode);

        assertSame(0, $exitCode, implode("\n", $lint));
    } finally {
        cleanupCrud($result['created']);
    }
});

test('make:crud: занятое имя без --force не трогает', function (): void {
    $first = runMakeCrud(['ZzCrudTaken'], ['fields' => 'title:string:Название']);

    try {
        assertSame(0, $first['status']);

        $second = runMakeCrud(['ZzCrudTaken'], ['fields' => 'title:string:Название']);

        assertSame(1, $second['status'], 'повтор без --force отказывает');
        assertSame([], $second['created'], 'второй прогон ничего не создал');
    } finally {
        cleanupCrud($first['created']);
    }
});
