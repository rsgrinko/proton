<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Console\Commands;

use Rsgrinko\Proton\Console\Command;

/**
 * стартовые данные.
 *
 * Сам файл лежит в приложении (database/seed.php) и возвращает функцию:
 * ядро не знает, чем наполнять чужой проект. Роли и первый пользователь
 * заводятся миграциями и страницей первого запуска, а не здесь.
 */
final class SeedCommand extends Command
{
    public function name(): string
    {
        return 'seed';
    }

    public function description(): string
    {
        return 'залить стартовые данные (database/seed.php)';
    }

    public function run(): int
    {
        $file = APP_ROOT . '/database/seed.php';

        if (!is_file($file)) {
            $this->line('Файл database/seed.php не найден — заливать нечего');

            return 0;
        }

        $seed = require $file;

        if (!is_callable($seed)) {
            $this->fail('database/seed.php должен возвращать функцию');

            return 1;
        }

        $seed(function (string $message): void {
            $this->ok($message);
        });

        $this->line('Готово');

        return 0;
    }
}
