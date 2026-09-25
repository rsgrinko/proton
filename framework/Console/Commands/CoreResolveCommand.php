<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Console\Commands;

use Rsgrinko\Proton\Console\Command;
use Rsgrinko\Proton\Core\Updater;

/**
 * отмечает файлы из ручного слияния разобранными: предком для них становится
 * версия ядра, и diff перестаёт их показывать, пока ядро не поменяет файл снова.
 */
final class CoreResolveCommand extends Command
{
    use UsesCoreSource;

    public function name(): string
    {
        return 'core:resolve';
    }

    public function description(): string
    {
        return 'отмечает файлы ручного слияния разобранными (--path=<каталог> или --remote)';
    }

    public function usage(): string
    {
        return 'core:resolve <файл> [<файл>...] --path=<каталог с ядром рядом> | --remote';
    }

    public function run(): int
    {
        $files = [];

        for ($i = 0; $this->arg($i) !== ''; $i++) {
            $files[] = $this->arg($i);
        }

        if ($files === []) {
            $this->fail('Укажите файлы из списка ручного слияния: core:resolve framework/Support/Csv.php --path=../proton');

            return 1;
        }

        $updater = new Updater(APP_ROOT);
        $path    = $this->coreSource($updater);

        if ($path === null) {
            return 1;
        }

        try {
            $unknown = $updater->resolve($path, $files);

            foreach ($unknown as $file) {
                $this->fail('Нет ни в ядре, ни в базе, пропущен: ' . $file);
            }

            $manual = $updater->diff($path)['manual'];

            if ($manual !== []) {
                $this->line('Ещё ждут ручного слияния:');

                foreach ($manual as $file) {
                    $this->line('  ' . $file);
                }

                return $unknown === [] ? 0 : 1;
            }

            $this->ok('Ручное слияние разобрано, версия ядра: ' . $updater->adoptVersion($path));
            $this->line('Дальше: php bin/proton test');

            return $unknown === [] ? 0 : 1;
        } finally {
            $this->releaseCoreSource($updater);
        }
    }
}
