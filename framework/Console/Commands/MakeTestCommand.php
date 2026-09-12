<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Console\Commands;

/**
 * создать файл тестов.
 */
final class MakeTestCommand extends MakeCommand
{
    public function name(): string
    {
        return 'make:test';
    }

    public function description(): string
    {
        return 'создать файл тестов в tests';
    }

    public function usage(): string
    {
        return 'make:test <Имя>';
    }

    protected function stub(): string
    {
        return 'test.stub';
    }

    protected function directory(): string
    {
        return 'tests';
    }

    protected function what(): string
    {
        return 'Файл тестов';
    }

    protected function suffix(): string
    {
        return 'Test';
    }

    protected function after(string $class, string $path): void
    {
        $this->line('  Запустить только его: php bin/proton test --filter=' . $class);
    }
}
