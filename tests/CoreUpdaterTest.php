<?php

declare(strict_types=1);

/**
 * Core\Updater — контрольные суммы, план обновления (safe/manual/removed),
 * сверка ссылок в клиентском коде и версия. Сценарии повторяют то, что
 * поймало реальный баг на живой симуляции переноса (см. docs/CORE_UPDATES.md):
 * markSynced() не должен тихо принимать недомерженный патч за новую базу.
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
    $updater->saveBaseline();

    // Ядро поменяло Permission.php — у нас его не трогали, безопасно взять
    file_put_contents($core . '/framework/Access/Permission.php', "<?php\n// новая версия ядра\n");

    // Мы сами поправили Logger.php — считается локальным патчем
    file_put_contents($local . '/framework/Support/Logger.php', "<?php\n// свой патч\n");

    $report = $updater->diff($core);

    assertSame(['framework/Access/Permission.php'], $report['safe']);
    assertSame(['framework/Support/Logger.php'], $report['manual']);
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

test('Updater: markSynced() не даёт недомерженному патчу тихо стать новой базой', function (): void {
    $local = makeUpdaterFixture();
    $core  = makeUpdaterFixture();

    $updater = new Updater($local);
    $updater->saveBaseline();

    // Свой патч — Logger.php; ядро в это же время поменяло Permission.php
    file_put_contents($local . '/framework/Support/Logger.php', "<?php\n// свой патч, не смёрджен\n");
    file_put_contents($core . '/framework/Access/Permission.php', "<?php\n// новая версия ядра\n");

    $report = $updater->diff($core);
    assertSame(['framework/Access/Permission.php'], $report['safe']);
    assertSame(['framework/Support/Logger.php'], $report['manual']);

    $copied = $updater->copyFiles($core, $report['safe']);
    $updater->markSynced($copied);

    // Патч в Logger.php должен по-прежнему считаться локальным — иначе
    // следующее обновление ядра затрёт его как «безопасный» файл
    assertSame(['framework/Support/Logger.php'], $updater->check());

    $nextReport = $updater->diff($core);
    assertSame([], $nextReport['safe'], 'Permission.php уже синхронизирован — повторно предлагать нечего');
    assertSame(['framework/Support/Logger.php'], $nextReport['manual'], 'Logger.php остаётся локальным патчем');
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
