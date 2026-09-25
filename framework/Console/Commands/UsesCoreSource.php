<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Console\Commands;

use Rsgrinko\Proton\Core\Updater;
use Rsgrinko\Proton\Support\Config;
use Rsgrinko\Proton\Support\ProtonException;

/**
 * Откуда взять ядро для сверки: --path=<каталог> или --remote (архив ветки
 * из PROTON_CORE_REPO). Скачанное убирает releaseCoreSource() — звать его
 * из finally, иначе при ошибке в var/tmp копятся распакованные архивы.
 */
trait UsesCoreSource
{
    private ?string $downloadedCore = null;

    /** Путь к ядру или null, если причину отказа уже напечатали */
    private function coreSource(Updater $updater): ?string
    {
        $path = (string) $this->option('path', '');

        if ($path !== '') {
            if (!is_dir(rtrim($path, '/\\') . '/framework')) {
                $this->fail('В ' . $path . ' нет каталога framework/');

                return null;
            }

            return $path;
        }

        if (!$this->hasOption('remote')) {
            $this->fail('Нужен --path=<каталог> или --remote');

            return null;
        }

        try {
            return $this->downloadedCore = $updater->downloadRemote(
                (string) Config::get('core.repo'),
                (string) Config::get('core.branch')
            );
        } catch (ProtonException $e) {
            $this->fail($e->getMessage());

            return null;
        }
    }

    private function releaseCoreSource(Updater $updater): void
    {
        if ($this->downloadedCore !== null) {
            $updater->cleanupRemote($this->downloadedCore);
            $this->downloadedCore = null;
        }
    }
}
