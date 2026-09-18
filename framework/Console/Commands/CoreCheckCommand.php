<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Console\Commands;

use Rsgrinko\Proton\Console\Command;
use Rsgrinko\Proton\Core\Updater;

/**
 * проверяет framework/ на локальные правки в обход правила «ядро не патчим».
 */
final class CoreCheckCommand extends Command
{
    public function name(): string
    {
        return 'core:check';
    }

    public function description(): string
    {
        return 'сверяет framework/ со своей последней синхронизацией; --baseline фиксирует текущее состояние как доверенное';
    }

    public function usage(): string
    {
        return 'core:check [--baseline]';
    }

    public function run(): int
    {
        $updater = new Updater(APP_ROOT);

        if ($this->hasOption('baseline')) {
            $updater->saveBaseline();
            $this->ok('Текущее состояние framework/ сохранено как доверенное (framework/.checksums.json)');

            return 0;
        }

        if ($updater->storedBaseline() === []) {
            $this->fail('Доверенного состояния ещё нет — сначала: php bin/proton core:check --baseline');

            return 1;
        }

        $changed = $updater->check();

        if ($changed === []) {
            $this->ok('framework/ не менялся с последней синхронизации');

            return 0;
        }

        $this->fail('Локально изменены (в обход правила «не трогать ядро руками»):');

        foreach ($changed as $path) {
            $this->line('  ' . $path);
        }

        return 1;
    }
}
