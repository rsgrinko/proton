<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Console\Commands;

use Rsgrinko\Proton\Console\Command;
use Rsgrinko\Proton\Files\Attachment;
use Rsgrinko\Proton\Files\Storage;
use Rsgrinko\Proton\Support\Str;

/**
 * файлы-сироты: лежат в хранилище, но ни к чему не приложены.
 *
 * Сироты появляются от сорвавшейся загрузки и от старых записей, удалённых
 * до того, как появилась таблица вложений. Команда сначала показывает список
 * и только с `--delete` удаляет: файл восстановить неоткуда, поэтому сносить
 * их вслепую нельзя.
 */
final class FilesOrphansCommand extends Command
{
    public function name(): string
    {
        return 'files:orphans';
    }

    public function description(): string
    {
        return 'найти файлы, на которые никто не ссылается';
    }

    public function usage(): string
    {
        return 'files:orphans [--delete] [--force]';
    }

    public function run(): int
    {
        $orphans = Attachment::orphans();

        if ($orphans === []) {
            $this->ok('Сирот нет: каждому файлу нашлась запись');

            return 0;
        }

        $bytes = 0;

        foreach ($orphans as $relative) {
            $path   = Storage::root() . '/' . $relative;
            $size   = is_file($path) ? (int) filesize($path) : 0;
            $bytes += $size;

            $this->line('  ' . $relative . '  ' . Str::bytes($size));
        }

        $this->line('');
        $this->line('Всего: ' . count($orphans) . ' файлов, ' . Str::bytes($bytes));

        if (!$this->hasOption('delete')) {
            $this->line('Удалить: php bin/proton files:orphans --delete');

            return 0;
        }

        if (!$this->confirm('Удалить эти файлы? Восстановить их будет неоткуда.')) {
            $this->line('Отменено');

            return 0;
        }

        $removed = 0;

        foreach ($orphans as $relative) {
            $removed += Storage::delete($relative) ? 1 : 0;
        }

        $this->ok('Удалено файлов: ' . $removed);

        return 0;
    }
}
