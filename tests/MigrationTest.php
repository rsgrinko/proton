<?php

declare(strict_types=1);

/**
 * Миграции: имена файлов, накат, откат и повторный накат.
 */

use Rsgrinko\Proton\Database\Connection;
use Rsgrinko\Proton\Database\Migrator;

test('миграции: имена файлов по правилу и классы на месте', function (): void {
    $migrations = (new Migrator())->migrations();

    assertTrue($migrations !== [], 'миграции должны находиться');

    foreach (array_keys($migrations) as $name) {
        assertMatches('/^\d{14}_[a-z0-9_]+$/', $name, 'имя файла миграции');
    }
});

test('миграции: все применены на тестовой базе', function (): void {
    assertCount(0, (new Migrator())->pending(), 'после старта прогона ждать нечему');
    assertCount(0, (new Migrator())->unknown(), 'лишних записей в таблице migrations быть не должно');
});

test('миграции: откат честно отменяет накат', function (): void {
    // Своя база: этот тест сносит схему целиком, соседям такое мешает
    withOwnDatabase(static function (Connection $db): void {
        $migrator = new Migrator($db);

        assertTrue($db->hasTable('users'), 'схема накатилась');

        $rolledBack = $migrator->rollback(10);

        assertTrue($rolledBack !== [], 'откат должен что-то вернуть');
        assertFalse($db->hasTable('users'), 'после отката таблиц быть не должно');
        assertFalse($db->hasTable('notes'));

        // Мигратор кэширует список — для повторного наката нужен свежий
        $applied = (new Migrator($db))->run();

        assertTrue($applied !== [], 'повторный накат должен пройти');
        assertTrue($db->hasTable('users'), 'схема вернулась');
        assertTrue($db->hasTable('roles'));
    });
});

test('миграции: pretend показывает запросы и ничего не делает', function (): void {
    withOwnDatabase(static function (Connection $db): void {
        (new Migrator($db))->rollback(10);

        $plan = (new Migrator($db))->pretend();

        assertTrue($plan !== [], 'план должен быть непустым');
        assertFalse($db->hasTable('users'), 'pretend ничего не выполняет');

        $queries = array_merge(...array_values($plan));

        assertTrue(count($queries) > 0);
        assertContains('CREATE TABLE', implode("\n", $queries));

        (new Migrator($db))->run();
    });
});

test('миграции: роли заводятся один раз, повтор ничего не задваивает', function (): void {
    withOwnDatabase(static function (Connection $db): void {
        $before = (int) $db->value('SELECT COUNT(*) FROM roles');

        // Повторный вызов посева внутри миграции не должен добавить вторых ролей
        (new Migrator($db))->run();

        assertSame($before, (int) $db->value('SELECT COUNT(*) FROM roles'));
        assertTrue($before >= 2, 'администратор и обычный пользователь');
    });
});
