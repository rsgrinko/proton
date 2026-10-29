<?php

declare(strict_types=1);

/**
 * DaemonCommand: круги, остановка (сам, флагом, сигналом), один экземпляр,
 * упавший круг и свежие настройки.
 */

use Rsgrinko\Proton\Console\Commands\DaemonStopCommand;
use Rsgrinko\Proton\Console\DaemonCommand;
use Rsgrinko\Proton\Database\Lock;
use Rsgrinko\Proton\Models\Setting;
use Rsgrinko\Proton\Support\Config;
use Rsgrinko\Proton\Support\Settings;

/**
 * Демон, у которого круг — переданная функция. Функция получает сам демон
 * и номер круга.
 */
final class DaemonTestCommand extends DaemonCommand
{
    public int $ticks = 0;

    /** @var array<int, string> */
    public array $lines = [];

    /**
     * @param Closure(self, int): void $body
     */
    public function __construct(private readonly Closure $body, private readonly string $daemonName = 'test:daemon')
    {
        parent::__construct();
    }

    public function name(): string
    {
        return $this->daemonName;
    }

    public function description(): string
    {
        return 'демон для теста';
    }

    protected function interval(): int
    {
        return 0;
    }

    protected function errorPause(): int
    {
        return 0;
    }

    protected function tick(): void
    {
        $this->ticks++;

        ($this->body)($this, $this->ticks);
    }

    public function finish(): void
    {
        $this->stop();
    }

    /**
     * @param array<string, string> $options
     */
    public function start(array $options = []): int
    {
        return $this->withInput([], $options, function (string $line): void {
            $this->lines[] = $line;
        })->run();
    }
}

/**
 * Свой каталог флагов остановки на время теста.
 */
function withDaemonFlags(callable $body): void
{
    $dir = sys_get_temp_dir() . '/proton-daemon-test-' . bin2hex(random_bytes(4));

    try {
        withConfig(['paths.daemon' => $dir], static fn () => $body($dir));
    } finally {
        foreach (glob($dir . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($dir);
    }
}

test('демон: --once делает ровно один круг', function (): void {
    withOwnDatabase(static function (): void {
        $daemon = new DaemonTestCommand(static function (): void {});

        assertSame(0, $daemon->start(['once' => '1']));
        assertSame(1, $daemon->ticks);
    });
});

test('демон: крутит круги, пока его не остановят', function (): void {
    withOwnDatabase(static function (): void {
        $daemon = new DaemonTestCommand(static function (DaemonTestCommand $daemon, int $tick): void {
            if ($tick === 5) {
                $daemon->finish();
            }
        });

        assertSame(0, $daemon->start());
        assertSame(5, $daemon->ticks);
    });
});

test('демон: упавший круг не роняет демон', function (): void {
    withOwnDatabase(static function (): void {
        $daemon = new DaemonTestCommand(static function (DaemonTestCommand $daemon, int $tick): void {
            if ($tick === 3) {
                $daemon->finish();
            }

            throw new RuntimeException('чужой сервис лежит');
        });

        assertSame(0, $daemon->start());
        assertSame(3, $daemon->ticks, 'после ошибок круги продолжились');
    });
});

test('демон: второй экземпляр с тем же именем не запускается', function (): void {
    withOwnDatabase(static function (): void {
        $held = new Lock(null, 'daemon:test:daemon');

        assertTrue($held->acquire(0));

        try {
            $daemon = new DaemonTestCommand(static function (): void {});

            assertSame(1, $daemon->start());
            assertSame(0, $daemon->ticks, 'ни одного круга');
            assertContains('уже запущен', implode("\n", $daemon->lines));
        } finally {
            $held->release();
        }

        // Блокировку демон за собой отпускает
        $daemon = new DaemonTestCommand(static function (): void {});

        assertSame(0, $daemon->start(['once' => '1']));

        $again = new Lock(null, 'daemon:test:daemon');

        assertTrue($again->acquire(0), 'после выхода блокировка свободна');
        $again->release();
    });
});

test('демон: выходит по флагу, а старый флаг нового не останавливает', function (): void {
    withOwnDatabase(static function (): void {
        withDaemonFlags(static function (): void {
            // Флаг остался от прошлого экземпляра — на новый он не действует
            DaemonCommand::requestStop('test:daemon');
            usleep(1000);

            $daemon = new DaemonTestCommand(static function (DaemonTestCommand $daemon, int $tick): void {
                if ($tick === 3) {
                    DaemonCommand::requestStop('test:daemon');
                }

                if ($tick > 10) {
                    $daemon->finish();
                }
            });

            assertSame(0, $daemon->start());
            assertSame(3, $daemon->ticks, 'вышел сразу после круга, на котором попросили');
        });
    });
});

test('демон: перед каждым кругом настройки свежие', function (): void {
    withOwnDatabase(static function (): void {
        withConfig(['ui.per_page' => 25], static function (): void {
            Settings::apply();

            $seen = [];

            $daemon = new DaemonTestCommand(static function (DaemonTestCommand $daemon, int $tick) use (&$seen): void {
                $seen[] = Config::get('ui.per_page');

                // Панель поменяла значение, пока демон работает
                Setting::set(Settings::PREFIX . 'ui.per_page', (string) (40 + $tick));

                if ($tick === 3) {
                    $daemon->finish();
                }
            });

            $daemon->start();

            assertSame([25, 41, 42], $seen);
        });
    });
});

test('демон: daemon:stop требует имя и ставит флаг', function (): void {
    withDaemonFlags(static function (string $dir): void {
        $lines = [];
        $out   = static function (string $line) use (&$lines): void {
            $lines[] = $line;
        };

        assertSame(1, (new DaemonStopCommand())->withInput([], [], $out)->run());
        assertSame(0, (new DaemonStopCommand())->withInput(['inbox:poll'], [], $out)->run());
        assertTrue(is_file($dir . '/inbox_poll.stop'), 'двоеточие в имени файла заменено');
    });
});

/**
 * Запускает tests/support/fake_daemon.php отдельным процессом и ждёт первых кругов.
 *
 * @return array{process: resource, pipes: array<int, resource>, report: string}
 */
function startFakeDaemon(string $name, string $flagsDir): array
{
    $report = sys_get_temp_dir() . '/proton-daemon-report-' . bin2hex(random_bytes(4)) . '.json';

    $process = proc_open(
        ['php', APP_ROOT . '/tests/support/fake_daemon.php', $name, $flagsDir, $report],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );

    $deadline = microtime(true) + 15;

    while (microtime(true) < $deadline) {
        $state = json_decode((string) @file_get_contents($report), true);

        if (is_array($state) && (int) ($state['ticks'] ?? 0) >= 2) {
            break;
        }

        usleep(50000);
    }

    return ['process' => $process, 'pipes' => $pipes, 'report' => $report];
}

/**
 * Ждёт выхода процесса. Возвращает отчёт демона или null, если не вышел.
 *
 * @param array{process: resource, pipes: array<int, resource>, report: string} $daemon
 *
 * @return array{ticks: int, exit: int|null}|null
 */
function waitFakeDaemon(array $daemon, float $seconds): ?array
{
    $deadline = microtime(true) + $seconds;

    while (microtime(true) < $deadline && proc_get_status($daemon['process'])['running']) {
        usleep(50000);
    }

    $running = proc_get_status($daemon['process'])['running'];

    foreach ($daemon['pipes'] as $pipe) {
        @fclose($pipe);
    }

    if ($running) {
        proc_terminate($daemon['process'], 9);
    }

    proc_close($daemon['process']);

    $report = json_decode((string) @file_get_contents($daemon['report']), true);

    @unlink($daemon['report']);

    return $running || !is_array($report) ? null : $report;
}

test('демон: отдельный процесс выходит по daemon:stop', function (): void {
    withDaemonFlags(static function (string $dir): void {
        $name   = 'test:proc-' . bin2hex(random_bytes(3));
        $daemon = startFakeDaemon($name, $dir);

        (new DaemonStopCommand())->withInput([$name], [], static function (): void {})->run();

        $report = waitFakeDaemon($daemon, 10);

        assertNotNull($report, 'процесс вышел сам, его не пришлось убивать');
        assertSame(0, $report['exit'], 'вышел по-хорошему, с кодом 0');
        assertTrue($report['ticks'] >= 2);
    });
});

test('демон: отдельный процесс доделывает круг и выходит по SIGTERM', function (): void {
    if (!function_exists('pcntl_signal') || PHP_OS_FAMILY === 'Windows') {
        skipTest('PCNTL недоступен');
    }

    withDaemonFlags(static function (string $dir): void {
        $daemon = startFakeDaemon('test:sig-' . bin2hex(random_bytes(3)), $dir);

        // proc_terminate() по умолчанию шлёт именно SIGTERM — как systemd stop
        proc_terminate($daemon['process']);

        $report = waitFakeDaemon($daemon, 10);

        assertNotNull($report, 'процесс вышел сам');
        assertSame(0, $report['exit'], 'вышел по-хорошему, а не убит сигналом');
    });
});
