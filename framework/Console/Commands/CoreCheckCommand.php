<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Console\Commands;

use Rsgrinko\Proton\Console\Command;
use Rsgrinko\Proton\Core\Updater;

/**
 * показывает, чем framework/ отличается от ядра последней синхронизации.
 */
final class CoreCheckCommand extends Command
{
    use UsesCoreSource;

    public function name(): string
    {
        return 'core:check';
    }

    public function description(): string
    {
        return 'сверяет framework/ с ядром последней синхронизации; --baseline заводит базу (от ядра через --path/--remote)';
    }

    public function usage(): string
    {
        return 'core:check [--baseline [--path=<ядро той версии, от которой заведён проект> | --remote]]';
    }

    public function run(): int
    {
        $updater = new Updater(APP_ROOT);

        if ($this->hasOption('baseline')) {
            return $this->baseline($updater);
        }

        if ($updater->storedBaseline() === []) {
            $this->fail('Доверенного состояния ещё нет — сначала: php bin/proton core:check --baseline --path=<ядро>');

            return 1;
        }

        $changed = $updater->check();

        if ($changed === []) {
            $this->ok('framework/ совпадает с ядром последней синхронизации');

            return 0;
        }

        $this->fail('Отличаются от ядра (свои правки или неразобранное ручное слияние):');

        foreach ($changed as $path) {
            $this->line('  ' . $path);
        }

        return 1;
    }

    private function baseline(Updater $updater): int
    {
        if (!$this->hasOption('path') && !$this->hasOption('remote')) {
            $updater->saveBaseline();
            $this->ok('База — текущий framework/ (framework/.checksums.json)');
            $this->line('Верно, только если в framework/ нет своих правок: иначе они станут невидимы');
            $this->line('и следующее обновление ядра их затрёт. С правками — --path=<ядро той же версии>.');

            return 0;
        }

        $path = $this->coreSource($updater);

        if ($path === null) {
            return 1;
        }

        try {
            $updater->saveBaseline($path);
            $this->ok('База — ядро ' . $updater->readVersion($path) . ' (framework/.checksums.json)');
        } finally {
            $this->releaseCoreSource($updater);
        }

        return 0;
    }
}
