<?php

declare(strict_types=1);

/**
 * Демон в отдельном процессе — только для tests/DaemonTest.php: остановка
 * по флагу и по SIGTERM проверяется на настоящем процессе, а не на объекте
 * внутри раннера.
 *
 * Аргументы: <имя> <каталог флагов> <файл отчёта>
 * Отчёт — JSON {ticks, exit}: сколько кругов сделал и с каким кодом вышел.
 * Каждый круг дописывает отметку, по ней тест понимает, что демон работает.
 */

require dirname(__DIR__, 2) . '/bootstrap.php';

use Rsgrinko\Proton\Console\DaemonCommand;
use Rsgrinko\Proton\Support\Config;

[$name, $flagsDir, $reportFile] = array_slice($argv, 1, 3) + ['', '', ''];

// База своя и пустая: настройки из боевой базы демону-подделке ни к чему,
// а блокировка в SQLite — файловая
Config::set('db', ['driver' => 'sqlite', 'sqlite' => ['path' => ':memory:']]);
Config::set('paths.log', APP_ROOT . '/var/log/tests');
Config::set('paths.daemon', $flagsDir);

$daemon = new class ($name, $reportFile) extends DaemonCommand {
    public int $ticks = 0;

    public function __construct(private readonly string $daemonName, private readonly string $reportFile)
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

    protected function tick(): void
    {
        $this->ticks++;

        file_put_contents($this->reportFile, json_encode(['ticks' => $this->ticks, 'exit' => null]));

        usleep(50000);
    }
};

$code = $daemon->withInput([], [])->run();

file_put_contents($reportFile, json_encode(['ticks' => $daemon->ticks, 'exit' => $code]));

exit($code);
