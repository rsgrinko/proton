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
