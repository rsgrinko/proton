<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Console\Commands;

use Rsgrinko\Proton\Console\Command;
use Rsgrinko\Proton\Core\Updater;
use Rsgrinko\Proton\Support\Config;

/**
 * версия и changelog ядра прямо из репозитория — два GET-запроса, без
 * авторизации и без похода в API (см. docs/CORE_UPDATES.md).
 */
final class CoreLatestCommand extends Command
{
    public function name(): string
    {
        return 'core:latest';
    }

    public function description(): string
    {
        return 'версия и changelog ядра из PROTON_CORE_REPO — с чем сравнить свою';
    }

    public function run(): int
    {
        $repo   = (string) Config::get('core.repo');
        $branch = (string) Config::get('core.branch');

        $updater = new Updater(APP_ROOT);
        $local   = $updater->localVersion();
        $remote  = $updater->remoteVersion($repo, $branch);

        if ($remote === '') {
            $this->fail('Не удалось прочитать версию ядра из ' . $repo . '@' . $branch);

            return 1;
        }

        $this->line('У вас: ' . $local);
        $this->line('В ' . $repo . '@' . $branch . ': ' . $remote);

        $cmp = version_compare($remote, $local);

        if ($cmp <= 0) {
            $this->ok('Вы на актуальной версии или свежее.');

            return 0;
        }

        $this->line();
        $changelog = Updater::changelogSince($updater->remoteChangelog($repo, $branch), $local);

        if ($changelog !== '') {
            $this->line($changelog);
        } else {
            $this->line('Отстаёте, но docs/CHANGELOG.md в ядре не прочитать или в нём нет записей новее вашей версии.');
        }

        return 0;
    }
}
