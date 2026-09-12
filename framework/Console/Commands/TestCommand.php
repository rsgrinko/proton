<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Console\Commands;

use Rsgrinko\Proton\Console\Command;

/**
 * прогнать тесты.
 */
final class TestCommand extends Command
{
    public function name(): string
    {
        return 'test';
    }

    public function description(): string
    {
        return 'прогнать тесты приложения';
    }

    public function usage(): string
    {
        return 'test [--filter=] [--stop-on-failure] [--shuffle[=seed]]';
    }

    public function run(): int
    {
        $runner = APP_ROOT . '/tests/run.php';

        if (!is_file($runner)) {
            $this->fail('Раннер тестов не найден: tests/run.php');

            return 1;
        }

        // Опции передаём раннеру как есть — он разбирает их сам
        $GLOBALS['test_options'] = $this->options;

        return (int) require $runner;
    }
}
