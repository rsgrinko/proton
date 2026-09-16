<?php

declare(strict_types=1);

/**
 * Резервные копии: создание, проверка, ротация, восстановление.
 *
 * Копии делаются на своей базе-файле: у базы в памяти копировать нечего,
 * а боевой файл тесты трогать не должны.
 */

use Rsgrinko\Proton\Backup\Backup;
use Rsgrinko\Proton\Database\Connection;
use Rsgrinko\Proton\Database\Migrator;
use Rsgrinko\Proton\Models\Setting;
use Rsgrinko\Proton\Support\Config;
use Rsgrinko\Proton\Support\ProtonException;

/**
 * Своя база-файл и свой каталог копий на время проверки.
 */
function withBackupSandbox(callable $body): void
{
    $file = testDatabaseFile('backup');
    $dir  = APP_ROOT . '/var/tmp/backups-tests';

    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    foreach (glob($dir . '/*') ?: [] as $path) {
        @unlink($path);
    }

    $previous = Connection::instance();

    withConfig([
        'db.driver'        => 'sqlite',
        'db.sqlite.path'   => $file,
        'backup.path'      => $dir,
        'backup.keep'      => 3,
    ], static function () use ($body, $previous, $dir, $file): void {
        Connection::setInstance(null);
        Connection::instance();
        (new Migrator())->run();

        try {
            $body();
        } finally {
            Connection::setInstance($previous);

            foreach (glob($dir . '/*') ?: [] as $path) {
                @unlink($path);
            }

            @unlink($file);
        }
    });
}

test('копии: создаётся, проверяется и лежит в своём каталоге', function (): void {
    withBackupSandbox(static function (): void {
        Setting::set('test:backup', 'значение до копии');

        $info = Backup::create();

        assertTrue($info['size'] > 0, 'файл не пустой');
        assertTrue($info['tables'] > 0, 'таблицы в копии есть');
        assertMatches('/^proton-\d{4}-\d{2}-\d{2}-\d{6}-\w{4}-sqlite\.sqlite\.gz$/', $info['name']);

        $files = Backup::files();

        assertCount(1, $files);
        assertSame($info['name'], $files[0]['name']);

        $check = Backup::verify($info['name']);

        assertTrue($check['ok'], 'свежая копия должна быть годной');
        assertTrue($check['tables'] > 0);
    });
});

test('копии: битый файл проверку не проходит', function (): void {
    withBackupSandbox(static function (): void {
        $info = Backup::create();
        $path = Backup::resolve($info['name']);

        // Портим середину архива: так ведёт себя копия, недописанная при сбое
        $raw = (string) file_get_contents($path);

        file_put_contents($path, substr($raw, 0, (int) (strlen($raw) / 2)));

        $check = Backup::verify($info['name']);

        assertFalse($check['ok'], 'обрезанный файл годным считаться не должен');
    });
});

test('копии: имя с путём не принимается', function (): void {
    withBackupSandbox(static function (): void {
        assertThrows(static function (): void {
            Backup::resolve('../../.env');
        }, 'выход за каталог копий запрещён');

        assertThrows(static function (): void {
            Backup::resolve('дамп.sql');
        }, 'принимаем только .gz');
    });
});

test('копии: лишние удаляются, свежие остаются', function (): void {
    withBackupSandbox(static function (): void {
        $names = [];

        // Имя копии — с секундами, поэтому файлы делаем сами: четыре за одну
        // секунду иначе слились бы в одно имя
        foreach (['0001', '0002', '0003', '0004', '0005'] as $index) {
            $name = 'proton-2026-09-1' . $index[3] . '-000000-sqlite.sqlite.gz';

            file_put_contents(Backup::directory() . '/' . $name, gzencode('заглушка'));

            $names[] = $name;
        }

        assertCount(5, Backup::files());

        $removed = Backup::rotate();

        assertSame(2, $removed, 'держим три последние');

        $left = array_map(static fn (array $file): string => $file['name'], Backup::files());

        assertCount(3, $left);
        assertTrue(in_array($names[4], $left, true), 'самая свежая осталась');
        assertFalse(in_array($names[0], $left, true), 'самая старая ушла');
    });
});

test('копии: восстановление возвращает данные и страхует прежние', function (): void {
    withBackupSandbox(static function (): void {
        Setting::set('test:backup', 'было до копии');

        $info = Backup::create();

        // Портим данные уже после копии
        Setting::set('test:backup', 'испорчено');

        assertSame('испорчено', Setting::get('test:backup'));

        $result = Backup::restore($info['name']);

        assertTrue($result['tables'] > 0);
        assertSame('было до копии', Setting::get('test:backup'), 'данные вернулись из копии');

        // Перед заменой сделана копия того, что было: есть куда откатиться
        $safety = Backup::verify($result['backup']);

        assertTrue($safety['ok'], 'страховочная копия тоже годна');
    });
});

test('копии: негодную не восстанавливаем', function (): void {
    withBackupSandbox(static function (): void {
        $name = 'proton-2026-09-13-000000-sqlite.sqlite.gz';

        file_put_contents(Backup::directory() . '/' . $name, gzencode('это не база'));

        $error = assertThrows(static function () use ($name): void {
            Backup::restore($name);
        });

        assertTrue($error instanceof ProtonException);
        assertContains('негодна', $error->getMessage());

        // И данные остались на месте: ничего не заменялось
        assertTrue(Connection::instance()->hasTable('users'));
    });
});

test('копии: расписание включается настройкой', function (): void {
    withConfig(['backup.schedule' => ''], static function (): void {
        Rsgrinko\Proton\Queue\Scheduler::reset();

        $names = array_column(Rsgrinko\Proton\Queue\Scheduler::tasks(), 'name');

        assertFalse(in_array('backup:database', $names, true), 'без времени копии не планируются');
    });

    withConfig(['backup.schedule' => '03:30'], static function (): void {
        Rsgrinko\Proton\Queue\Scheduler::reset();

        $tasks = Rsgrinko\Proton\Queue\Scheduler::tasks();
        $names = array_column($tasks, 'name');

        assertTrue(in_array('backup:database', $names, true), 'со временем — задача появилась');

        foreach ($tasks as $task) {
            if ($task['name'] === 'backup:database') {
                assertSame('03:30', $task['at']);
            }
        }
    });

    // Соседям расписание нужно в исходном виде
    Rsgrinko\Proton\Queue\Scheduler::reset();
});

test('копии: размер показывается по-человечески', function (): void {
    assertSame('1,0 КБ', Backup::size(1024));
    assertSame('13,2 КБ', Backup::size(13517));
});

test('копии: временные файлы проверки подчищаются, свежие не трогаются', function (): void {
    $dir = APP_ROOT . '/var/tmp/backup-purge-tests';

    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    withConfig(['paths.tmp' => $dir], static function () use ($dir): void {
        $stale = $dir . '/backup-check-stale.sqlite';
        $fresh = $dir . '/backup-check-fresh.sqlite';

        file_put_contents($stale, 'x');
        file_put_contents($fresh, 'x');

        // «Старым» файл считаем по времени изменения — трогаем его в прошлое,
        // а не ждём час в тесте
        touch($stale, time() - 7200);

        $removed = Backup::purgeTemp(60);

        assertSame(1, $removed);
        assertFalse(is_file($stale), 'зависший файл старше часа убран');
        assertTrue(is_file($fresh), 'свежий не тронут — проверка может идти прямо сейчас');
    });

    @unlink($dir . '/backup-check-fresh.sqlite');
    @rmdir($dir);
});

test('копии: каталог создаётся сам', function (): void {
    $dir = APP_ROOT . '/var/tmp/backups-fresh';

    if (is_dir($dir)) {
        rmdir($dir);
    }

    withConfig(['backup.path' => $dir], static function () use ($dir): void {
        assertSame(str_replace('\\', '/', $dir), Backup::directory());
        assertTrue(is_dir($dir));
    });

    rmdir($dir);
});

afterTests(static function (): void {
    $dir = APP_ROOT . '/var/tmp/backups-tests';

    foreach (glob($dir . '/*') ?: [] as $path) {
        @unlink($path);
    }

    if (is_dir($dir)) {
        @rmdir($dir);
    }
});
