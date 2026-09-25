<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Console\Commands;

use Rsgrinko\Proton\Console\Command;
use Rsgrinko\Proton\Core\Updater;

/**
 * копирует из ядра то, что безопасно копировать. Без --apply — тот же план,
 * что у core:diff, ничего не пишется: цена ошибки здесь выше, чем у migrate.
 */
final class CoreSyncCommand extends Command
{
    use UsesCoreSource;

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
            $this->fail('Доверенного состояния ещё нет — сначала: php bin/proton core:check --baseline --path=<ядро>');

            return 1;
        }

        $path = $this->coreSource($updater);

        if ($path === null) {
            return 1;
        }

        try {
            return $this->sync($updater, $path, $this->hasOption('apply'));
        } finally {
            $this->releaseCoreSource($updater);
        }
    }

    private function sync(Updater $updater, string $path, bool $apply): int
    {
        $report = $updater->diff($path);

        if (!$apply) {
            $this->line('Только план — для записи добавьте --apply. Что было бы скопировано:');
            $this->printList($report['safe']);
        } else {
            $copied = $updater->copyFiles($path, $report['safe']);
            $updater->markSynced($path, $report['manual']);

            $this->ok('Скопировано файлов: ' . count($copied));
            $this->printList($copied);
        }

        if ($report['manual'] !== []) {
            $this->line();
            $this->line('Требуют ручного слияния (не тронуты):');
            $this->printList($report['manual']);
        }

        if ($report['removed'] !== []) {
            $this->line();
            $this->line('Ядро больше не содержит (не удалены, решать вам):');
            $this->printList($report['removed']);
        }

        if ($report['broken'] !== []) {
            $this->line();
            $this->line('Возможно сломается в вашем коде — проверьте после слияния:');

            foreach (array_keys($report['broken']) as $symbol) {
                $this->line('  ' . $symbol);
            }
        }

        if (!$apply) {
            return 0;
        }

        $this->line();

        if ($report['manual'] === []) {
            $this->ok('Версия ядра: ' . $updater->adoptVersion($path));
            $this->line('Дальше: php bin/proton test');
        } else {
            $this->line('Версия осталась ' . $updater->localVersion() . ' — станет ' . $report['version_core']
                . ', когда core:resolve отметит последний файл из ручного слияния.');
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
