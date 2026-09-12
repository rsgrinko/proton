<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Console\Commands;

/**
 * создать консольную команду.
 */
final class MakeCommandCommand extends MakeCommand
{
    public function name(): string
    {
        return 'make:command';
    }

    public function description(): string
    {
        return 'создать консольную команду в app/Commands';
    }

    public function usage(): string
    {
        return 'make:command <Имя>';
    }

    protected function stub(): string
    {
        return 'command.stub';
    }

    protected function directory(): string
    {
        return 'app/Commands';
    }

    protected function what(): string
    {
        return 'Команда';
    }

    protected function suffix(): string
    {
        return 'Command';
    }

    protected function after(string $class, string $path): void
    {
        $this->line('  Впишите её в config/commands.php — иначе она не появится в справке.');
    }
}
