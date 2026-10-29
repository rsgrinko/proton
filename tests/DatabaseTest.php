<?php

declare(strict_types=1);

/**
 * Подключение и построитель запросов.
 */

use Rsgrinko\Proton\Database\Connection;
use Rsgrinko\Proton\Database\DatabaseException;

test('база: вставка, чтение, обновление и удаление', function (): void {
    $db = Connection::instance();

    $id = $db->insert('settings', ['setting_key' => 'test.crud', 'value' => 'раз', 'updated_at' => Connection::now()]);

    assertTrue($id >= 0, 'вставка должна пройти');
    assertSame('раз', (string) $db->value('SELECT value FROM settings WHERE setting_key = :key', ['key' => 'test.crud']));

    $db->update('settings', ['value' => 'два'], ['setting_key' => 'test.crud']);

    assertSame('два', (string) $db->value('SELECT value FROM settings WHERE setting_key = :key', ['key' => 'test.crud']));

    $db->delete('settings', ['setting_key' => 'test.crud']);

    assertNull($db->selectOne('SELECT value FROM settings WHERE setting_key = :key', ['key' => 'test.crud']));
});

test('построитель: условия, порядок и предел', function (): void {
    $db = Connection::instance();

    foreach ([['a', '1'], ['b', '2'], ['c', '3']] as [$key, $value]) {
        $db->insert('settings', ['setting_key' => 'q.' . $key, 'value' => $value, 'updated_at' => Connection::now()]);
    }

    afterTests(static function () use ($db): void {
        $db->execute("DELETE FROM settings WHERE setting_key LIKE 'q.%'");
    });

    $rows = $db->table('settings')
        ->whereLike('setting_key', 'q.')
        ->orderBy('setting_key', 'desc')
        ->limit(2)
        ->get();

    assertCount(2, $rows);
    assertSame('q.c', (string) $rows[0]['setting_key']);

    assertSame(3, $db->table('settings')->whereLike('setting_key', 'q.')->count());
    assertTrue($db->table('settings')->where('setting_key', 'q.a')->exists());
    assertFalse($db->table('settings')->where('setting_key', 'q.zzz')->exists());
});

test('построитель: скобки и «или»', function (): void {
    $db = Connection::instance();

    foreach ([['x', '10'], ['y', '20'], ['z', '30']] as [$key, $value]) {
        $db->insert('settings', ['setting_key' => 'g.' . $key, 'value' => $value, 'updated_at' => Connection::now()]);
    }

    afterTests(static function () use ($db): void {
        $db->execute("DELETE FROM settings WHERE setting_key LIKE 'g.%'");
    });

    $rows = $db->table('settings')
        ->whereLike('setting_key', 'g.')
        ->where(static function ($query): void {
            $query->where('value', '10')->orWhere('value', '30');
        })
        ->orderBy('value')
        ->get();

    assertCount(2, $rows);
    assertSame('10', (string) $rows[0]['value']);
});

test('построитель: каждое значение получает своё имя параметра', function (): void {
    // MySQL идёт без эмуляции подготовленных выражений и повтор имени
    // не принимает — поэтому имена обязаны быть разными
    $query = Connection::instance()->table('settings')
        ->where('setting_key', 'a')
        ->where('value', 'b')
        ->whereIn('setting_key', ['c', 'd']);

    $names = array_keys($query->bindings());

    assertSame(count($names), count(array_unique($names)), 'имена параметров не должны повторяться');
    assertCount(4, $names);
});

test('построитель: удаление без условий запрещено', function (): void {
    $error = assertThrows(static function (): void {
        Connection::instance()->table('settings')->delete();
    });

    assertTrue($error instanceof DatabaseException, 'ожидалась ошибка базы');
    assertContains('без условий', $error->getMessage());
});

test('построитель: чужое имя колонки не проходит', function (): void {
    assertThrows(static function (): void {
        Connection::instance()->table('settings')->where('value; DROP TABLE users', '1');
    }, 'подозрительное имя колонки должно отвергаться');
});

test('база: страница результатов считает всё и отдаёт кусок', function (): void {
    $db = Connection::instance();

    for ($i = 1; $i <= 7; $i++) {
        $db->insert('settings', ['setting_key' => 'p.' . $i, 'value' => (string) $i, 'updated_at' => Connection::now()]);
    }

    afterTests(static function () use ($db): void {
        $db->execute("DELETE FROM settings WHERE setting_key LIKE 'p.%'");
    });

    $page = $db->table('settings')->whereLike('setting_key', 'p.')->orderBy('setting_key')->paginate(2, 3);

    assertSame(7, $page['total']);
    assertSame(3, $page['pages']);
    assertSame(2, $page['page']);
    assertCount(3, $page['items']);
});

test('база: cursor() отдаёт строки по одной, а не всей выборкой разом', function (): void {
    $db = Connection::instance();

    for ($i = 1; $i <= 5; $i++) {
        $db->insert('settings', ['setting_key' => 'cursor.test.' . $i, 'value' => (string) $i, 'updated_at' => Connection::now()]);
    }

    afterTests(static function () use ($db): void {
        $db->execute("DELETE FROM settings WHERE setting_key LIKE 'cursor.test.%'");
    });

    $cursor = $db->table('settings')->whereLike('setting_key', 'cursor.test.')->orderBy('setting_key')->cursor();

    assertTrue($cursor instanceof Generator, 'генератор, а не готовый массив');

    $values = [];

    foreach ($cursor as $row) {
        $values[] = (string) $row['value'];
    }

    assertSame(['1', '2', '3', '4', '5'], $values);
});

test('база: транзакция откатывается при ошибке', function (): void {
    $db = Connection::instance();

    assertThrows(static function () use ($db): void {
        $db->transaction(static function (Connection $db): void {
            $db->insert('settings', ['setting_key' => 'tx.test', 'value' => '1', 'updated_at' => Connection::now()]);

            throw new RuntimeException('намеренная ошибка');
        });
    });

    assertNull($db->selectOne('SELECT value FROM settings WHERE setting_key = :key', ['key' => 'tx.test']));
});

test('база: знает о своих таблицах, колонках и индексах', function (): void {
    $db = Connection::instance();

    assertTrue($db->hasTable('users'));
    assertFalse($db->hasTable('таблицы_такой_нет'));
    assertTrue($db->hasColumn('users', 'login'));
    assertFalse($db->hasColumn('users', 'колонки_такой_нет'));
    assertTrue($db->hasIndex('users', 'idx_users_login'));
});

/**
 * Своя таблица на время теста: ключ-значение и записи с датами.
 */
function withScratchTables(callable $body): void
{
    withOwnDatabase(static function (Connection $db) use ($body): void {
        $schema = new Rsgrinko\Proton\Database\Schema\Builder($db);

        $schema->create('test_pairs', static function (Rsgrinko\Proton\Database\Schema\Blueprint $table): void {
            $table->string('pair_key', 64)->primary();
            $table->string('value', 64);
            $table->integer('hits')->default(0);
        });

        $schema->create('test_events', static function (Rsgrinko\Proton\Database\Schema\Blueprint $table): void {
            $table->id();
            $table->dateTime('happened_at');
            $table->date('happened_on');
            $table->index('idx_test_events_happened_at', 'happened_at');
        });

        $body($db);
    });
}

test('построитель: upsert вставляет новую строку и перезаписывает существующую', function (): void {
    withScratchTables(static function (Connection $db): void {
        $db->table('test_pairs')->upsert(['pair_key' => 'a', 'value' => 'раз', 'hits' => 1], ['pair_key']);
        $db->table('test_pairs')->upsert(['pair_key' => 'a', 'value' => 'два', 'hits' => 5], ['pair_key']);

        $rows = $db->select('SELECT * FROM test_pairs');

        assertCount(1, $rows, 'строка с тем же ключом не задвоилась');
        assertSame('два', (string) $rows[0]['value']);
        assertSame(5, (int) $rows[0]['hits']);
    });
});

test('построитель: upsert обновляет только перечисленные колонки', function (): void {
    withScratchTables(static function (Connection $db): void {
        $db->table('test_pairs')->upsert(['pair_key' => 'a', 'value' => 'раз', 'hits' => 1], ['pair_key']);
        $db->table('test_pairs')->upsert(['pair_key' => 'a', 'value' => 'два', 'hits' => 9], ['pair_key'], ['hits']);

        $row = assertNotNull($db->selectOne('SELECT * FROM test_pairs WHERE pair_key = :k', ['k' => 'a']));

        assertSame('раз', (string) $row['value'], 'value не в списке — не тронуто');
        assertSame(9, (int) $row['hits']);

        // Пустой список — «есть, так не трогать», и это не ошибка
        $db->table('test_pairs')->upsert(['pair_key' => 'a', 'value' => 'три', 'hits' => 0], ['pair_key'], []);

        assertSame('раз', (string) $db->value('SELECT value FROM test_pairs WHERE pair_key = :k', ['k' => 'a']));
    });
});

test('построитель: upsert пачкой и мимо накопленных условий', function (): void {
    withScratchTables(static function (Connection $db): void {
        $db->table('test_pairs')->upsert(['pair_key' => 'a', 'value' => 'старое', 'hits' => 0], ['pair_key']);

        // where() к вставке отношения не имеет и не должен ломать параметры
        $db->table('test_pairs')->where('hits', '>', 100)->upsert([
            ['pair_key' => 'a', 'value' => 'новое', 'hits' => 1],
            ['pair_key' => 'b', 'value' => 'второе', 'hits' => 2],
        ], ['pair_key']);

        assertSame(
            ['a' => 'новое', 'b' => 'второе'],
            array_column($db->select('SELECT pair_key, value FROM test_pairs ORDER BY pair_key'), 'value', 'pair_key')
        );
    });
});

test('построитель: upsert отвергает ключ не из вставляемых колонок', function (): void {
    $error = assertThrows(static fn () => Connection::instance()->table('test_pairs')->upsert(['value' => 'x'], ['pair_key']));

    assertContains('ключа', $error->getMessage());

    assertThrows(static fn () => Connection::instance()->table('test_pairs')->upsert([
        ['pair_key' => 'a', 'value' => 'x'],
        ['value' => 'y', 'pair_key' => 'b'],
    ], ['pair_key']), 'разный порядок колонок в пачке');
});

test('построитель: whereDate сравнивает по дню и для dateTime, и для date', function (): void {
    withScratchTables(static function (Connection $db): void {
        foreach (['2026-10-28 23:59:59', '2026-10-29 00:00:00', '2026-10-29 12:30:00', '2026-10-29 23:59:59', '2026-10-30 00:00:00'] as $moment) {
            $db->insert('test_events', ['happened_at' => $moment, 'happened_on' => substr($moment, 0, 10)]);
        }

        $count = static fn (string $column, string $operator, string|DateTimeInterface $date): int
            => $db->table('test_events')->whereDate($column, $operator, $date)->count();

        foreach (['happened_at', 'happened_on'] as $column) {
            assertSame(3, $count($column, '=', '2026-10-29'), $column . ' =');
            assertSame(2, $count($column, '<>', '2026-10-29'), $column . ' <>');
            assertSame(1, $count($column, '<', '2026-10-29'), $column . ' <');
            assertSame(4, $count($column, '<=', '2026-10-29'), $column . ' <=');
            assertSame(1, $count($column, '>', '2026-10-29'), $column . ' >');
            assertSame(4, $count($column, '>=', '2026-10-29'), $column . ' >=');
        }

        // Объект даты: время отбрасывается, считается только день
        assertSame(3, $count('happened_at', '=', new DateTimeImmutable('2026-10-29 17:45:00')));
    });
});

test('построитель: whereDate не принимает кривую дату и чужой оператор', function (): void {
    $query = static fn () => Connection::instance()->table('settings');

    assertThrows(static fn () => $query()->whereDate('updated_at', '=', '29.10.2026'));
    assertThrows(static fn () => $query()->whereDate('updated_at', '=', '2026-02-30'), 'несуществующий день');
    assertThrows(static fn () => $query()->whereDate('updated_at', 'LIKE', '2026-10-29'));
});
