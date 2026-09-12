<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Console\Commands;

use Rsgrinko\Proton\Auth\Crypto;
use Rsgrinko\Proton\Console\Command;
use Rsgrinko\Proton\Support\Config;

/**
 * ключ приложения.
 *
 * Ключом подписываются куки и шифруются секреты в базе. Менять его на живом
 * проекте нельзя: зашифрованные значения станут нечитаемыми, а все куки
 * недействительными — поэтому существующий ключ команда не трогает без --force.
 */
final class AppKeyCommand extends Command
{
    public function name(): string
    {
        return 'app:key';
    }

    public function description(): string
    {
        return 'создать APP_KEY и записать его в .env';
    }

    public function usage(): string
    {
        return 'app:key [--force] [--show]';
    }

    public function run(): int
    {
        $key = Crypto::generateKey();

        if ($this->hasOption('show')) {
            $this->line($key);

            return 0;
        }

        $file = APP_ROOT . '/.env';

        if (!is_file($file)) {
            $this->fail('Файл .env не найден. Скопируйте .env.example в .env и повторите');

            return 1;
        }

        $current = (string) Config::get('app.key', '');

        if ($current !== '' && !$this->hasOption('force')) {
            $this->fail('APP_KEY уже задан. Смена ключа сделает нечитаемым всё зашифрованное; если это осознанно — --force');

            return 1;
        }

        $content = (string) file_get_contents($file);

        $content = preg_match('/^APP_KEY=.*$/m', $content) === 1
            ? (string) preg_replace('/^APP_KEY=.*$/m', 'APP_KEY=' . $key, $content)
            : rtrim($content) . PHP_EOL . 'APP_KEY=' . $key . PHP_EOL;

        if (@file_put_contents($file, $content) === false) {
            $this->fail('Не удалось записать .env');

            return 1;
        }

        $this->ok('APP_KEY записан в .env');

        return 0;
    }
}
