<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Console\Commands;

use Rsgrinko\Proton\Console\Command;
use Rsgrinko\Proton\Core\Updater;

/**
 * бампает версию ядра — руками, не хуком на каждый коммит: версия размечает
 * точку, к которой имеет смысл синхронизировать другой проект, не коммиты.
 */
final class CoreBumpCommand extends Command
{
    public function name(): string
    {
        return 'core:bump';
    }

    public function description(): string
    {
        return 'бампает версию ядра (по умолчанию — патч) и дописывает docs/CHANGELOG.md';
    }

    public function usage(): string
    {
        return 'core:bump [patch|minor|major] [--note="..."] [--breaking]';
    }

    public function run(): int
    {
        $level = $this->arg(0, 'patch');

        if (!in_array($level, ['patch', 'minor', 'major'], true)) {
            $this->fail('Уровень — patch, minor или major, не "' . $level . '"');

            return 1;
        }

        $updater = new Updater(APP_ROOT);
        $before  = $updater->localVersion();
        $note    = (string) $this->option('note', '');
        $after   = $updater->bump($level, $note, $this->hasOption('breaking'));

        $this->ok($before . ' → ' . $after);

        if ($note === '') {
            $this->line('Запись в docs/CHANGELOG.md не добавлена — не передан --note');
        }

        return 0;
    }
}
