<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Console\Commands;

use Rsgrinko\Proton\Cache\Cache;
use Rsgrinko\Proton\Console\Command;

/**
 * очистить кэш.
 */
final class CacheClearCommand extends Command
{
    public function name(): string
    {
        return 'cache:clear';
    }

    public function description(): string
    {
        return 'очистить кэш приложения';
    }

    public function run(): int
    {
        Cache::flush();

        $this->ok('Кэш очищен');

        return 0;
    }
}
