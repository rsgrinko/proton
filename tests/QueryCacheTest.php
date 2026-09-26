<?php

declare(strict_types=1);

/**
 * Кэш результата запроса: Builder::remember() поверх Support\Cache.
 */

use Rsgrinko\Proton\Cache\Cache;
use Rsgrinko\Proton\Database\Connection;

test('запрос: remember() отдаёт старое значение, пока не истёк срок', function (): void {
    $db  = Connection::instance();
    $key = 'test.remember.' . bin2hex(random_bytes(4));

    $db->insert('settings', ['setting_key' => $key, 'value' => 'раз', 'updated_at' => Connection::now()]);

    try {
        $first = $db->table('settings')->where('setting_key', $key)->remember(60)->value('value');

        assertSame('раз', (string) $first);

        // Меняем в обход построителя — если кэш не сработал, второй вызов увидит новое значение
        $db->update('settings', ['value' => 'два'], ['setting_key' => $key]);

        $second = $db->table('settings')->where('setting_key', $key)->remember(60)->value('value');

        assertSame('раз', (string) $second, 'второй вызов должен взять значение из кэша, а не из базы');
    } finally {
        $db->delete('settings', ['setting_key' => $key]);
    }
});

test('запрос: без remember() каждый вызов бьёт в базу заново', function (): void {
    $db  = Connection::instance();
    $key = 'test.live.' . bin2hex(random_bytes(4));

    $db->insert('settings', ['setting_key' => $key, 'value' => 'раз', 'updated_at' => Connection::now()]);

    try {
        assertSame('раз', (string) $db->table('settings')->where('setting_key', $key)->value('value'));

        $db->update('settings', ['value' => 'два'], ['setting_key' => $key]);

        assertSame('два', (string) $db->table('settings')->where('setting_key', $key)->value('value'));
    } finally {
        $db->delete('settings', ['setting_key' => $key]);
    }
});

test('запрос: разные условия — разные ключи кэша, не путаются', function (): void {
    $db     = Connection::instance();
    $prefix = 'test.keys.' . bin2hex(random_bytes(4)) . '.';

    $db->insert('settings', ['setting_key' => $prefix . 'a', 'value' => 'а', 'updated_at' => Connection::now()]);
    $db->insert('settings', ['setting_key' => $prefix . 'b', 'value' => 'б', 'updated_at' => Connection::now()]);

    try {
        $a = $db->table('settings')->where('setting_key', $prefix . 'a')->remember(60)->value('value');
        $b = $db->table('settings')->where('setting_key', $prefix . 'b')->remember(60)->value('value');

        assertSame('а', (string) $a);
        assertSame('б', (string) $b);
    } finally {
        $db->execute("DELETE FROM settings WHERE setting_key LIKE :prefix", ['prefix' => $prefix . '%']);
    }
});

test('запрос: свой ключ кэша можно сбросить явно через Cache::forget()', function (): void {
    $db  = Connection::instance();
    $key = 'test.named.' . bin2hex(random_bytes(4));

    $db->insert('settings', ['setting_key' => $key, 'value' => 'раз', 'updated_at' => Connection::now()]);

    try {
        $cacheKey = 'test:named:' . $key;

        $first = $db->table('settings')->where('setting_key', $key)->remember(60, $cacheKey)->value('value');
        assertSame('раз', (string) $first);

        $db->update('settings', ['value' => 'два'], ['setting_key' => $key]);

        // value() внутри зовёт first() -> get(), поэтому у ключа есть суффикс ":get"
        Cache::forget('query:' . $cacheKey . ':get');

        $second = $db->table('settings')->where('setting_key', $key)->remember(60, $cacheKey)->value('value');
        assertSame('два', (string) $second, 'после forget() кэш должен пересчитаться');
    } finally {
        Cache::forget('query:test:named:' . $key . ':get');
        $db->delete('settings', ['setting_key' => $key]);
    }
});

test('запрос: count() тоже кэшируется', function (): void {
    $db     = Connection::instance();
    $prefix = 'test.count.' . bin2hex(random_bytes(4)) . '.';

    $db->insert('settings', ['setting_key' => $prefix . 'a', 'value' => '1', 'updated_at' => Connection::now()]);

    try {
        $before = $db->table('settings')->whereLike('setting_key', $prefix)->remember(60)->count();

        assertSame(1, $before);

        $db->insert('settings', ['setting_key' => $prefix . 'b', 'value' => '2', 'updated_at' => Connection::now()]);

        $after = $db->table('settings')->whereLike('setting_key', $prefix)->remember(60)->count();

        assertSame(1, $after, 'значение из кэша — новую строку ещё не увидели');
    } finally {
        $db->execute("DELETE FROM settings WHERE setting_key LIKE :prefix", ['prefix' => $prefix . '%']);
    }
});

test('запрос: свой ключ не склеивает страницы paginate() в одну', function (): void {
    $db     = Connection::instance();
    $prefix = 'test.pages.' . bin2hex(random_bytes(4)) . '.';
    $key    = 'test:pages:' . bin2hex(random_bytes(4));

    foreach (['a', 'b', 'c'] as $suffix) {
        $db->insert('settings', ['setting_key' => $prefix . $suffix, 'value' => $suffix, 'updated_at' => Connection::now()]);
    }

    try {
        $page = static fn (int $number): array => array_column(
            $db->table('settings')->whereLike('setting_key', $prefix)->orderBy('setting_key')
                ->remember(60, $key)->paginate($number, 1)['items'],
            'value'
        );

        assertSame(['a'], $page(1));
        assertSame(['b'], $page(2), 'вторая страница — своя, а не первая из кэша');
        assertSame(['c'], $page(3));

        // forget() по ключу сбрасывает все страницы разом
        $db->update('settings', ['value' => 'A'], ['setting_key' => $prefix . 'a']);
        Cache::forget('query:' . $key . ':get');

        assertSame(['A'], $page(1));
    } finally {
        Cache::forget('query:' . $key . ':get');
        Cache::forget('query:' . $key . ':count:*');
        $db->execute('DELETE FROM settings WHERE setting_key LIKE :p', ['p' => $prefix . '%']);
    }
});

test('запрос: пустой ответ тоже кэшируется, а не идёт в базу каждый раз', function (): void {
    $db  = Connection::instance();
    $key = 'test.empty.' . bin2hex(random_bytes(4));

    try {
        assertNull($db->table('settings')->where('setting_key', $key)->remember(60)->first());

        $db->insert('settings', ['setting_key' => $key, 'value' => 'появилось', 'updated_at' => Connection::now()]);

        assertNull($db->table('settings')->where('setting_key', $key)->remember(60)->first(), 'пустота из кэша, строку ещё не видим');
    } finally {
        $db->delete('settings', ['setting_key' => $key]);
    }
});
