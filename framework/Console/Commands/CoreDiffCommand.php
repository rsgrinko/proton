<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Console\Commands;

use Rsgrinko\Proton\Console\Command;
use Rsgrinko\Proton\Core\Updater;
use Rsgrinko\Proton\Support\Config;
use Rsgrinko\Proton\Support\ProtonException;

/**
 * что можно взять из ядра, что требует ручного слияния, что ядро больше не
 * содержит — только смотрит, ничего не пишет.
 */
final class CoreDiffCommand extends Command
{
    public function name(): string
    {
        return 'core:diff';
    }

    public function description(): string
    {
        return 'план обновления ядра: что скопировать, что мержить руками, что удалено (--path=<каталог> или --remote)';
    }

    public function usage(): string
    {
        return 'core:diff --path=<каталог с ядром рядом> | --remote';
    }

    public function run(): int
    {
        $updater = new Updater(APP_ROOT);

        if ($updater->storedBaseline() === []) {
            $this->fail('Доверенного состояния ещё нет — сначала: php bin/proton core:check --baseline');

            return 1;
        }

        $path      = $this->option('path', '');
        $temporary = null;

        if ($path === null || $path === '') {
            if (!$this->hasOption('remote')) {
                $this->fail('Нужен --path=<каталог> или --remote');

                return 1;
            }

            try {
                $path = $temporary = $updater->downloadRemote(
                    (string) Config::get('core.repo'),
                    (string) Config::get('core.branch')
                );
            } catch (ProtonException $e) {
                $this->fail($e->getMessage());

                return 1;
            }
        }

        $report = $updater->diff($path);

        if ($temporary !== null) {
            $updater->cleanupRemote($temporary);
        }

        $this->printReport($report);

        return 0;
    }

    /**
     * @param array{version_local: string, version_core: string, safe: array<int, string>, manual: array<int, string>, removed: array<int, string>, broken: array<string, array<int, string>>} $report
     */
    private function printReport(array $report): void
    {
        $this->line('Ядро сейчас: ' . $report['version_core'] . ' (у вас: ' . $report['version_local'] . ')');
        $this->line();

        $this->line('Можно взять безопасно:');
        $this->printList($report['safe'], 'пусто');

        $this->line();
        $this->line('Требуют ручного слияния:');
        $this->printList($report['manual'], 'пусто');

        $this->line();
        $this->line('Ядро больше не содержит:');
        $this->printList($report['removed'], 'пусто');

        if ($report['broken'] !== []) {
            $this->line();
            $this->line('Ссылки в вашем коде, которые могут сломаться после sync:');

            foreach ($report['broken'] as $symbol => $locations) {
                $this->line('  ' . $symbol);

                foreach ($locations as $location) {
                    $this->line('    ' . $location);
                }
            }
        }
    }

    /**
     * @param array<int, string> $items
     */
    private function printList(array $items, string $whenEmpty): void
    {
        if ($items === []) {
            $this->line('  — (' . $whenEmpty . ')');

            return;
        }

        foreach ($items as $item) {
            $this->line('  ' . $item);
        }
    }
}
