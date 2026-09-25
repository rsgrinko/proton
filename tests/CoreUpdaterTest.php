<?php

declare(strict_types=1);

/**
 * Core\Updater — контрольные суммы, трёхсторонний план обновления
 * (safe/manual/removed) от базы-ядра, разбор ручного слияния, сверка ссылок
 * в клиентском коде, версия и скачивание архива. Сценарии повторяют то, что
 * ломалось на живой симуляции переноса (см. docs/CORE_UPDATES.md).
 */

use Rsgrinko\Proton\Core\Updater;
use Rsgrinko\Proton\Support\HttpClient;

/**
 * Готовит временный "проект": framework/ с парой файлов и клиентские
 * каталоги, которые могут на них ссылаться. Возвращает путь к каталогу.
 */
function makeUpdaterFixture(): string
{
    $root = APP_ROOT . '/var/tmp/updater-test-' . bin2hex(random_bytes(6));

    mkdir($root . '/framework/Access', 0777, true);
    mkdir($root . '/framework/Support', 0777, true);
    mkdir($root . '/app/Controllers', 0777, true);

    file_put_contents($root . '/framework/Access/Permission.php', implode("\n", [
        '<?php',
        'namespace Rsgrinko\\Proton\\Access;',
        'final class Permission',
        '{',
        "    public const USERS_MANAGE = 'users.manage';",
        '}',
        '',
    ]));

    file_put_contents($root . '/framework/Support/Logger.php', implode("\n", [
        '<?php',
        'namespace Rsgrinko\\Proton\\Support;',
        'final class Logger',
        '{',
        '    public function info(string $message): void {}',
        '}',
        '',
    ]));

    file_put_contents($root . '/app/Controllers/DemoController.php', implode("\n", [
        '<?php',
        'namespace App\\Controllers;',
        'use Rsgrinko\\Proton\\Access\\Permission;',
        'use Rsgrinko\\Proton\\Support\\Logger;',
        'final class DemoController',
        '{',
        '    public function show(): string',
        '    {',
        '        (new Logger())->info(Permission::USERS_MANAGE);',
        '',
        '        return Permission::class;',
        '    }',
        '}',
        '',
    ]));

    afterTests(static function () use ($root): void {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($root);
    });

    return $root;
}

/** Одна запись ustar: заголовок 512 байт и содержимое, добитое до кратного 512 */
function tarEntry(string $name, string $content): string
{
    $header = str_pad($name, 100, "\0")
        . sprintf("%07o\0", 0644) . sprintf("%07o\0", 0) . sprintf("%07o\0", 0)
        . sprintf("%011o\0", strlen($content)) . sprintf("%011o\0", 0)
        . '        ' . '0' . str_repeat("\0", 100)
        . "ustar\0" . '00';
    $header = str_pad($header, 512, "\0");

    // Контрольная сумма считается с пробелами на месте своего поля
    $sum    = array_sum(array_map('ord', str_split($header)));
    $header = substr_replace($header, sprintf("%06o\0 ", $sum), 148, 8);

    return $header . str_pad($content, (int) ceil(strlen($content) / 512) * 512, "\0");
}

test('Updater: контрольные суммы не видят разницы в переводе строк', function (): void {
    $root = makeUpdaterFixture();

    $unix = (new Updater($root))->checksums($root);

    $withCrlf = str_replace("\n", "\r\n", (string) file_get_contents($root . '/framework/Support/Logger.php'));
    file_put_contents($root . '/framework/Support/Logger.php', $withCrlf);

    $afterCrlf = (new Updater($root))->checksums($root);

    assertSame($unix['framework/Support/Logger.php'], $afterCrlf['framework/Support/Logger.php']);
});

test('Updater: check() молчит без изменений и находит их после правки', function (): void {
    $root    = makeUpdaterFixture();
    $updater = new Updater($root);

    $updater->saveBaseline();
    assertSame([], $updater->check(), 'сразу после baseline менять нечего');

    file_put_contents($root . '/framework/Support/Logger.php', "<?php\n// патч\n");

    assertSame(['framework/Support/Logger.php'], $updater->check());
});

test('Updater: diff() различает "взять безопасно", "мержить руками" и "ядро удалило"', function (): void {
    $local = makeUpdaterFixture();
    $core  = makeUpdaterFixture();

    $updater = new Updater($local);
    $updater->saveBaseline($core);

    // Ядро поменяло Permission.php — у нас его не трогали, безопасно взять
    file_put_contents($core . '/framework/Access/Permission.php', "<?php\n// новая версия ядра\n");

    // Logger.php поменяли и мы, и ядро — только руками
    file_put_contents($local . '/framework/Support/Logger.php', "<?php\n// свой патч\n");
    file_put_contents($core . '/framework/Support/Logger.php', "<?php\n// правка ядра\n");

    $report = $updater->diff($core);

    assertSame(['framework/Access/Permission.php'], $report['safe']);
    assertSame(['framework/Support/Logger.php'], $report['manual']);
    assertSame([], $report['removed']);
});

test('Updater: свой патч в файле, который ядро не меняло, не требует слияния', function (): void {
    $local = makeUpdaterFixture();
    $core  = makeUpdaterFixture();

    $updater = new Updater($local);
    $updater->saveBaseline($core);

    file_put_contents($local . '/framework/Support/Logger.php', "<?php\n// свой патч\n");

    $report = $updater->diff($core);

    assertSame([], $report['safe']);
    assertSame([], $report['manual'], 'ядро файл не трогало — сливать нечего');
    assertSame(['framework/Support/Logger.php'], $updater->check(), 'а check() патч по-прежнему видит');
});

test('Updater: файл без предка не затирается, свой файл в framework/ не считается удалённым ядром', function (): void {
    $local = makeUpdaterFixture();
    $core  = makeUpdaterFixture();

    $updater = new Updater($local);
    $updater->saveBaseline($core);

    // Своего файла в ядре нет вовсе, а одноимённый с ядром появился у обоих без общего предка
    file_put_contents($local . '/framework/Support/Own.php', "<?php\n// своё\n");
    file_put_contents($local . '/framework/Support/Twin.php', "<?php\n// наш вариант\n");
    file_put_contents($core . '/framework/Support/Twin.php', "<?php\n// вариант ядра\n");

    $report = $updater->diff($core);

    assertSame(['framework/Support/Twin.php'], $report['manual']);
    assertSame([], $report['removed']);
});

test('Updater: удалённый в ядре файл, на который ссылается свой код, попадает и в removed, и в broken', function (): void {
    $local = makeUpdaterFixture();
    $core  = makeUpdaterFixture();

    (new Updater($local))->saveBaseline();
    unlink($core . '/framework/Support/Logger.php');

    $report = (new Updater($local))->diff($core);

    assertSame(['framework/Support/Logger.php'], $report['removed']);
    assertTrue(isset($report['broken']['Rsgrinko\\Proton\\Support\\Logger']), 'ссылка на удалённый класс должна попасть в broken');
});

test('Updater: ::class и унаследованные методы не считаются пропавшими', function (): void {
    $root = makeUpdaterFixture();

    // DemoController зовёт Permission::class (магическая константа) — её
    // не должно быть ни в каком реальном классе
    $report = (new Updater($root))->diff($root);

    assertSame([], $report['broken'], '::class и найденные члены не должны попадать в список поломок');
});

test('Updater: реально пропавший метод/константа попадает в broken, а не молчит', function (): void {
    $local = makeUpdaterFixture();
    $core  = makeUpdaterFixture();

    // В новом ядре Logger лишился info() — используется в app/Controllers/DemoController.php
    file_put_contents($core . '/framework/Access/Permission.php', implode("\n", [
        '<?php',
        'namespace Rsgrinko\\Proton\\Access;',
        'final class Permission',
        '{',
        '}',
        '',
    ]));

    $report = (new Updater($local))->diff($core);

    assertTrue(isset($report['broken']['Rsgrinko\\Proton\\Access\\Permission::USERS_MANAGE']));
});

test('Updater: markSynced() держит недомерженный файл в ручном слиянии, пока его не разобрали', function (): void {
    $local = makeUpdaterFixture();
    $core  = makeUpdaterFixture();

    $updater = new Updater($local);
    $updater->saveBaseline($core);

    // Logger.php поменяли оба; Permission.php — только ядро
    file_put_contents($local . '/framework/Support/Logger.php', "<?php\n// свой патч, не слит\n");
    file_put_contents($core . '/framework/Support/Logger.php', "<?php\n// правка ядра\n");
    file_put_contents($core . '/framework/Access/Permission.php', "<?php\n// новая версия ядра\n");

    $report = $updater->diff($core);
    $updater->copyFiles($core, $report['safe']);
    $updater->markSynced($core, $report['manual']);

    // Если бы база Logger.php стала ядром, файл выглядел бы «своим патчем»,
    // и правка ядра в нём потерялась бы молча
    $next = $updater->diff($core);
    assertSame([], $next['safe'], 'Permission.php уже взят — повторно предлагать нечего');
    assertSame(['framework/Support/Logger.php'], $next['manual']);
});

test('Updater: resolve() снимает файл с ручного слияния, а новая правка ядра возвращает его', function (): void {
    $local = makeUpdaterFixture();
    $core  = makeUpdaterFixture();

    $updater = new Updater($local);
    $updater->saveBaseline($core);

    file_put_contents($local . '/framework/Support/Logger.php', "<?php\n// свой патч\n");
    file_put_contents($core . '/framework/Support/Logger.php', "<?php\n// правка ядра\n");

    $report = $updater->diff($core);
    $updater->markSynced($core, $report['manual']);

    // Слили руками: правка ядра плюс свой патч
    file_put_contents($local . '/framework/Support/Logger.php', "<?php\n// правка ядра\n// свой патч\n");

    assertSame([], $updater->resolve($core, ['Support/Logger.php']), 'путь внутри framework/ тоже годится');
    assertSame([], $updater->diff($core)['manual'], 'после resolve файл больше не всплывает');
    assertSame(['framework/Support/Logger.php'], $updater->check(), 'свой патч остаётся видимым');

    file_put_contents($core . '/framework/Support/Logger.php', "<?php\n// ещё одна правка ядра\n");

    assertSame(['framework/Support/Logger.php'], $updater->diff($core)['manual']);
    assertSame(['framework/Nope.php'], $updater->resolve($core, ['framework/Nope.php']));
});

test('Updater: adoptVersion() ставит себе версию ядра', function (): void {
    $local = makeUpdaterFixture();
    $core  = makeUpdaterFixture();

    file_put_contents($local . '/framework/VERSION', "1.0.0\n");
    file_put_contents($core . '/framework/VERSION', "1.2.0\n");

    $updater = new Updater($local);

    assertSame('1.2.0', $updater->adoptVersion($core));
    assertSame('1.2.0', $updater->localVersion());
});

test('Updater: архив с GitHub берётся с codeload и распаковывается без sys_temp_dir', function (): void {
    $root = makeUpdaterFixture();

    // Архив как у GitHub: служебный pax_global_header рядом с <repo>-<ветка>/.
    // Собран руками: PharData при записи сам лезет в sys_temp_dir
    $tar = tarEntry('pax_global_header', 'comment=abc')
        . tarEntry('proton-main/framework/VERSION', "2.0.0\n")
        . str_repeat("\0", 1024);

    $url = 'https://codeload.github.com/acme/proton/tar.gz/refs/heads/main';
    HttpClient::fake([$url => ['status' => 200, 'body' => (string) gzencode($tar)]]);

    $updater = new Updater($root);

    try {
        assertSame($url, $updater->archiveUrl('https://github.com/acme/proton/', 'main'));

        $path = $updater->downloadRemote('https://github.com/acme/proton', 'main');

        assertSame('2.0.0', $updater->readVersion($path));

        $updater->cleanupRemote($path);
        assertFalse(is_dir(dirname($path)), 'временный каталог убран целиком');
    } finally {
        HttpClient::reset();
    }
});

test('Updater: nextVersion() считает по правилам семвера со сбросом младших разрядов', function (): void {
    assertSame('1.4.28', Updater::nextVersion('1.4.27', 'patch'));
    assertSame('1.5.0', Updater::nextVersion('1.4.27', 'minor'));
    assertSame('2.0.0', Updater::nextVersion('1.4.27', 'major'));
});

test('Updater: bump() правит VERSION и дописывает CHANGELOG сверху', function (): void {
    $root = makeUpdaterFixture();
    mkdir($root . '/docs', 0777, true);

    file_put_contents($root . '/framework/VERSION', "1.0.0\n");
    file_put_contents($root . '/docs/CHANGELOG.md', "# Журнал изменений ядра\n\n## 1.0.0\n- начало\n");

    $updater = new Updater($root);
    $new     = $updater->bump('minor', 'Новая фича', false);

    assertSame('1.1.0', $new);
    assertSame('1.1.0', $updater->localVersion());

    $changelog = (string) file_get_contents($root . '/docs/CHANGELOG.md');
    assertTrue(str_contains($changelog, "## 1.1.0\n- Новая фича"));
    assertTrue(
        (int) strpos($changelog, '## 1.1.0') < (int) strpos($changelog, '## 1.0.0'),
        'новая запись должна лечь выше старой'
    );
});

test('Updater: bump() не ставит запись выше вводного абзаца журнала', function (): void {
    $root = makeUpdaterFixture();
    mkdir($root . '/docs', 0777, true);

    file_put_contents($root . '/framework/VERSION', "1.0.0\n");
    file_put_contents($root . '/docs/CHANGELOG.md', "# Журнал\n\nВводный абзац.\n\n## 1.0.0\n- начало\n");

    (new Updater($root))->bump('minor', 'Новая фича', false);

    assertSame(
        "# Журнал\n\nВводный абзац.\n\n## 1.1.0\n- Новая фича\n\n## 1.0.0\n- начало\n",
        (string) file_get_contents($root . '/docs/CHANGELOG.md')
    );
});

test('Updater: bump() с --breaking помечает запись явно', function (): void {
    $root = makeUpdaterFixture();
    mkdir($root . '/docs', 0777, true);
    file_put_contents($root . '/framework/VERSION', "1.0.0\n");

    (new Updater($root))->bump('major', 'Права переехали на view/manage', true);

    $changelog = (string) file_get_contents($root . '/docs/CHANGELOG.md');
    assertTrue(str_contains($changelog, '**Ломает:** Права переехали на view/manage'));
});

test('Updater: remoteVersion()/remoteChangelog() читают raw-контент через HttpClient', function (): void {
    HttpClient::fake([
        'https://raw.githubusercontent.com/acme/proton/main/framework/VERSION' => ['status' => 200, 'body' => "1.6.2\n"],
        'https://raw.githubusercontent.com/acme/proton/main/docs/CHANGELOG.md' => [
            'status' => 200,
            'body'   => "# Журнал\n\n## 1.6.0\n- новое\n\n## 1.4.0\n- старое\n",
        ],
    ]);

    try {
        $updater = new Updater(APP_ROOT);

        assertSame('1.6.2', $updater->remoteVersion('https://github.com/acme/proton', 'main'));

        $changelog = Updater::changelogSince($updater->remoteChangelog('https://github.com/acme/proton', 'main'), '1.4.27');

        assertTrue(str_contains($changelog, '## 1.6.0'));
        assertFalse(str_contains($changelog, '## 1.4.0'), 'запись старше локальной версии не нужна');
    } finally {
        HttpClient::reset();
    }
});

test('Updater: remoteVersion() пустой строкой сигналит о неудаче, не падением', function (): void {
    HttpClient::fake(['*' => ['status' => 404, 'body' => 'Not Found']]);

    try {
        assertSame('', (new Updater(APP_ROOT))->remoteVersion('https://github.com/acme/proton', 'main'));
    } finally {
        HttpClient::reset();
    }
});
