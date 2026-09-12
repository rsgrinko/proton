<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Console\Commands;

/**
 * создать контроллер.
 */
final class MakeControllerCommand extends MakeCommand
{
    public function name(): string
    {
        return 'make:controller';
    }

    public function description(): string
    {
        return 'создать контроллер в app/Controllers';
    }

    public function usage(): string
    {
        return 'make:controller <Имя> [--admin]';
    }

    protected function stub(): string
    {
        return 'controller.stub';
    }

    protected function directory(): string
    {
        return $this->hasOption('admin') ? 'app/Controllers/Admin' : 'app/Controllers/Web';
    }

    protected function what(): string
    {
        return 'Контроллер';
    }

    protected function suffix(): string
    {
        return 'Controller';
    }

    /**
     * @return array<string, string>
     */
    protected function replacements(string $class): array
    {
        return array_merge(parent::replacements($class), [
            '{{namespace}}' => $this->hasOption('admin') ? 'App\\Controllers\\Admin' : 'App\\Controllers\\Web',
        ]);
    }

    protected function after(string $class, string $path): void
    {
        $this->line('  Осталось добавить маршрут в routes/' . ($this->hasOption('admin') ? 'admin.php' : 'web.php'));
    }
}
