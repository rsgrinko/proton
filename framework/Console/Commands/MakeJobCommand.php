<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Console\Commands;

/**
 * создать задачу очереди.
 */
final class MakeJobCommand extends MakeCommand
{
    public function name(): string
    {
        return 'make:job';
    }

    public function description(): string
    {
        return 'создать задачу очереди в app/Jobs';
    }

    public function usage(): string
    {
        return 'make:job <Имя>';
    }

    protected function stub(): string
    {
        return 'job.stub';
    }

    protected function directory(): string
    {
        return 'app/Jobs';
    }

    protected function what(): string
    {
        return 'Задача';
    }

    protected function after(string $class, string $path): void
    {
        $this->line('  Поставить в очередь: Queue::push(App\\Jobs\\' . $class . '::class, [...]);');
    }
}
