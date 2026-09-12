<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Console\Commands;

use Rsgrinko\Proton\Console\Command;
use Rsgrinko\Proton\Support\Str;

/**
 * создать миграцию.
 *
 * Имя файла — отметка времени плюс имя: 20260912093000_create_notes.php.
 * По отметке считается порядок наката, поэтому берётся текущее время, а не
 * номер по счёту: два человека, работающие параллельно, не столкнутся.
 */
final class MakeMigrationCommand extends Command
{
    public function name(): string
    {
        return 'make:migration';
    }

    public function description(): string
    {
        return 'создать файл миграции';
    }

    public function usage(): string
    {
        return 'make:migration <имя_миграции> [--table=]';
    }

    public function run(): int
    {
        $raw = trim($this->arg(0));

        if ($raw === '') {
            $this->fail('Укажите имя: php bin/proton make:migration create_notes');

            return 1;
        }

        $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9_]+/', '_', $raw));
        $slug = trim($slug, '_');

        if ($slug === '') {
            $this->fail('Имя миграции должно состоять из латиницы, цифр и подчёркиваний');

            return 1;
        }

        $class = Str::studly($slug);
        $name  = date('YmdHis') . '_' . $slug;
        $path  = APP_ROOT . '/migrations/' . $name . '.php';

        // Таблицу угадываем по имени: create_notes -> notes
        $table = (string) $this->option('table', '');

        if ($table === '' && preg_match('/^(create|add_.*_to|drop)_([a-z0-9_]+?)(_table)?$/', $slug, $matches) === 1) {
            $table = $matches[2];
        }

        $stub = APP_ROOT . '/stubs/migration.stub';

        $content = is_file($stub)
            ? strtr((string) file_get_contents($stub), ['{{class}}' => $class, '{{table}}' => $table !== '' ? $table : 'table_name'])
            : "<?php\n\ndeclare(strict_types=1);\n";

        if (@file_put_contents($path, $content) === false) {
            $this->fail('Не удалось записать файл ' . $path);

            return 1;
        }

        $this->ok('Миграция создана: migrations/' . $name . '.php');
        $this->line('  Накатить: php bin/proton migrate');

        return 0;
    }
}
