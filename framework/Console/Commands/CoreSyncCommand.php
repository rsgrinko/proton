<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Console\Commands;

use Rsgrinko\Proton\Console\Command;
use Rsgrinko\Proton\Core\Updater;
use Rsgrinko\Proton\Support\Config;
use Rsgrinko\Proton\Support\ProtonException;

/**
 * копирует из ядра то, что безопасно копировать. Без --apply — тот же план,
 * что у core:diff, ничего не пишется: цена ошибки здесь выше, чем у migrate.
 */
final class CoreSyncCommand extends Command
{
    public function name(): string
    {
        return 'core:sync';
    }

    public function description(): string
    {
        return 'копирует безопасную часть обновления ядра; без --apply — только план (--path=<каталог> или --remote)';
    }

    public function usage(): string
    {
        return 'core:sync --path=<каталог с ядром рядом> | --remote [--apply]';
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
        $apply  = $this->hasOption('apply');

        if (!$apply) {
            $this->line('Только план — для записи добавьте --apply. Что было бы скопировано:');
            $this->printList($report['safe']);
        } else {
            $copied = $updater->copyFiles($path, $report['safe']);
            $updater->markSynced($copied);

            $this->ok('Скопировано файлов: ' . count($copied));
            $this->printList($copied);
        }

        if ($report['manual'] !== []) {
            $this->line();
            $this->line('Требуют ручного слияния (не тронуты):');
            $this->printList($report['manual']);
        }

        if ($report['broken'] !== []) {
            $this->line();
            $this->line('Возможно сломается в вашем коде — проверьте после слияния:');

            foreach (array_keys($report['broken']) as $symbol) {
                $this->line('  ' . $symbol);
            }
        }

        if ($temporary !== null) {
            $updater->cleanupRemote($temporary);
        }

        if ($apply) {
            $this->line();
            $this->line('Дальше: php bin/proton test, затем php bin/proton core:bump, когда список ручного слияния разобран.');
        }

        return 0;
    }

    /**
     * @param array<int, string> $items
     */
    private function printList(array $items): void
    {
        if ($items === []) {
            $this->line('  — (пусто)');

            return;
        }

        foreach ($items as $item) {
            $this->line('  ' . $item);
        }
    }
}
