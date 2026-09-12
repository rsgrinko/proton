<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Console\Commands;

/**
 * создать модель.
 */
final class MakeModelCommand extends MakeCommand
{
    public function name(): string
    {
        return 'make:model';
    }

    public function description(): string
    {
        return 'создать модель в app/Models';
    }

    public function usage(): string
    {
        return 'make:model <Имя>';
    }

    protected function stub(): string
    {
        return 'model.stub';
    }

    protected function directory(): string
    {
        return 'app/Models';
    }

    protected function what(): string
    {
        return 'Модель';
    }

    protected function after(string $class, string $path): void
    {
        $this->line('  Не забудьте миграцию: php bin/proton make:migration create_' . $this->replacements($class)['{{table}}']);
    }
}
