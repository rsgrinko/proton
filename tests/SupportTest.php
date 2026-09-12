<?php

declare(strict_types=1);

/**
 * Мелочи, на которых всё держится: кэш, события, счётчики, логи, файлы,
 * адреса за прокси и контейнер.
 */

use Rsgrinko\Proton\Cache\Cache;
use Rsgrinko\Proton\Events\Events;
use Rsgrinko\Proton\Files\Storage;
use Rsgrinko\Proton\Files\UploadedFile;
use Rsgrinko\Proton\RateLimit\RateLimiter;
use Rsgrinko\Proton\Support\ClientIp;
use Rsgrinko\Proton\Support\Container;
use Rsgrinko\Proton\Support\IpAllowlist;
use Rsgrinko\Proton\Support\Logger;
use Rsgrinko\Proton\Support\ProtonException;
use Rsgrinko\Proton\Support\Str;

test('кэш: значение живёт до срока и считается один раз', function (): void {
    $calls = 0;

    $factory = static function () use (&$calls): string {
        $calls++;

        return 'значение';
    };

    Cache::forget('test:cache');

    assertSame('значение', Cache::remember('test:cache', 60, $factory));
    assertSame('значение', Cache::remember('test:cache', 60, $factory));
    assertSame(1, $calls, 'второй раз должен прийти кэш');

    Cache::forget('test:cache');

    assertNull(Cache::get('test:cache'));
});

test('кэш: нулевой срок означает «не кэшировать»', function (): void {
    $calls = 0;

    for ($i = 0; $i < 2; $i++) {
        Cache::remember('test:zero', 0, static function () use (&$calls): int {
            return ++$calls;
        });
    }

    assertSame(2, $calls);
});

test('события: слушатели зовутся по приоритету, упавший не роняет остальных', function (): void {
    Events::reset();

    $order = [];

    Events::listen('test.event', static function () use (&$order): void {
        $order[] = 'второй';
    }, 0);

    Events::listen('test.event', static function () use (&$order): void {
        $order[] = 'первый';
    }, 10);

    Events::listen('test.event', static function (): void {
        throw new RuntimeException('слушатель упал');
    }, 5);

    Events::fire('test.event');

    assertSame(['первый', 'второй'], $order, 'падение соседа не мешает остальным');

    Events::reset();
});

test('события: фильтр пропускает значение по цепочке', function (): void {
    Events::reset();

    Events::listen('test.filter', static fn (int $value): int => $value + 1);
    Events::listen('test.filter', static fn (int $value): int => $value * 2);

    assertSame(4, Events::filter('test.filter', 1));

    Events::reset();
});

test('счётчики: окно закрывается и открывается заново', function (): void {
    $limiter = new RateLimiter();
    $key     = 'test:limit:' . bin2hex(random_bytes(3));

    assertSame(0, $limiter->count($key));

    $limiter->hit($key, time() + 60);
    $limiter->hit($key, time() + 60);

    assertSame(2, $limiter->count($key));

    // Окно кончилось: строка ещё лежит, но считается нулём — иначе блокировка
    // держалась бы дольше обещанного, до самой чистки воркером
    Rsgrinko\Proton\Database\Connection::instance()->update(
        'counters',
        ['expires_at' => date('Y-m-d H:i:s', time() - 10)],
        ['counter_key' => $key]
    );

    assertSame(0, $limiter->count($key));

    // И следующее попадание открывает новое окно, а не продолжает прежний счёт
    $limiter->hit($key, time() + 60);

    assertSame(1, $limiter->count($key));

    $limiter->reset($key);
});

test('счётчики: attempt пускает ровно столько раз, сколько разрешено', function (): void {
    $limiter = new RateLimiter();
    $key     = 'test:attempt:' . bin2hex(random_bytes(3));

    assertTrue($limiter->attempt($key, 2, 60));
    assertTrue($limiter->attempt($key, 2, 60));
    assertFalse($limiter->attempt($key, 2, 60), 'третий раз уже нельзя');

    $limiter->reset($key);
});

test('логи: запись пишется одной строкой и разбирается обратно', function (): void {
    $logger = new Logger('tests');

    $logger->error("Ошибка с переносом\nво второй строке", ['ключ' => 'значение']);

    $lines = $logger->tail(basename($logger->file()), 5);

    assertTrue($lines !== [], 'запись должна появиться');

    $parsed = Logger::parse($lines[count($lines) - 1]);

    assertSame('error', $parsed['level']);
    assertSame('tests', $parsed['channel']);
    assertContains('|', $parsed['message'], 'перенос схлопнут в разделитель');
    assertContains('значение', $parsed['context']);
});

test('файлы: хранилище принимает файл и не пускает за свои пределы', function (): void {
    $temp = (string) tempnam(sys_get_temp_dir(), 'proton');

    file_put_contents($temp, 'содержимое файла');

    $stored = Storage::put(new UploadedFile('документ.txt', $temp, filesize($temp) ?: 0, UPLOAD_ERR_OK), 'tests');

    assertTrue(Storage::exists($stored['path']));
    assertSame('содержимое файла', Storage::read($stored['path']));
    assertNotContains('документ', $stored['path'], 'имя на диске своё, случайное');

    assertThrows(static function (): void {
        Storage::path('../../.env');
    }, 'выход за пределы хранилища запрещён');

    Storage::delete($stored['path']);
});

test('файлы: запрещённое расширение не принимается', function (): void {
    $temp = (string) tempnam(sys_get_temp_dir(), 'proton');

    file_put_contents($temp, '<?php echo "привет"; ?>');

    $error = assertThrows(static function () use ($temp): void {
        Storage::put(new UploadedFile('шелл.php', $temp, filesize($temp) ?: 0, UPLOAD_ERR_OK));
    });

    assertTrue($error instanceof ProtonException);

    @unlink($temp);
});

test('адрес клиента: заголовку верим только от своего прокси', function (): void {
    withConfig(['app.trusted_proxies' => ''], static function (): void {
        assertSame('10.0.0.1', ClientIp::resolve('10.0.0.1', '203.0.113.7'), 'без доверенных прокси заголовок игнорируется');
    });

    withConfig(['app.trusted_proxies' => '10.0.0.0/8'], static function (): void {
        assertSame('203.0.113.7', ClientIp::resolve('10.0.0.1', '203.0.113.7, 10.0.0.2'));
        assertSame('10.0.0.1', ClientIp::resolve('10.0.0.1', ''), 'пустой заголовок ничего не меняет');
    });
});

test('адреса: список разрешённых понимает сети и IPv6', function (): void {
    assertTrue(IpAllowlist::allows('127.0.0.1, 10.0.0.0/8', '10.5.4.3'));
    assertFalse(IpAllowlist::allows('127.0.0.1, 10.0.0.0/8', '192.168.0.1'));
    assertTrue(IpAllowlist::allows('2001:db8::/32', '2001:db8::5'));
    assertFalse(IpAllowlist::allows('', '127.0.0.1'), 'пустой список не разрешает никого');

    assertTrue(IpAllowlist::valid('10.0.0.0/8'));
    assertFalse(IpAllowlist::valid('не адрес'));
});

test('строки: адресная часть, обрезка и размеры', function (): void {
    assertSame('privet-mir', Str::slug('Привет, мир!'));
    assertSame('hello-world', Str::slug('Hello World'));
    assertSame('note_event', Str::snake('NoteEvent'));
    assertSame('NoteEvent', Str::studly('note_event'));
    assertSame('categories', Str::plural('category'));
    assertSame('boxes', Str::plural('box'));
    assertSame('1,5 КБ', Str::bytes(1536));
    assertSame('коро…', Str::limit('коротко', 5));
});

test('строки: битая кодировка не превращает адрес в пустоту', function (): void {
    $broken = mb_convert_encoding('Проверка', 'Windows-1251', 'UTF-8');

    assertSame('proverka', Str::slug($broken));
});

test('контейнер: собирает зависимости и отдаёт подложенное', function (): void {
    $container = new Container();

    $logger = new Logger('подложенный');

    $container->set(Logger::class, $logger);

    assertTrue($container->make(Logger::class) === $logger);

    $error = assertThrows(static function () use ($container): void {
        $container->make(DateTimeZone::class);
    });

    assertTrue($error instanceof ProtonException, 'чужие классы контейнер не собирает');
});
