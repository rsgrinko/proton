<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Console\Commands;

use Rsgrinko\Proton\Console\Command;
use Rsgrinko\Proton\Support\Diagnostics;

/**
 * состояние приложения.
 */
final class StatusCommand extends Command
{
    public function name(): string
    {
        return 'status';
    }

    public function description(): string
    {
        return 'самопроверка: окружение, база, ключи, очередь';
    }

    public function run(): int
    {
        $checks = Diagnostics::run();
        $rows   = [];

        foreach ($checks as $check) {
            $rows[] = [$this->mark($check['level']), $check['title'], $check['value']];
        }

        $this->table(['', 'Проверка', 'Значение'], $rows);

        $hints = array_filter($checks, static fn (array $check): bool => $check['hint'] !== '');

        if ($hints !== []) {
            $this->line('');
            $this->line('Что сделать:');

            foreach ($hints as $check) {
                $this->line('  ' . $check['title'] . ': ' . $check['hint']);
            }
        }

        return Diagnostics::worst($checks) === Diagnostics::ERROR ? 1 : 0;
    }

    private function mark(string $level): string
    {
        return match ($level) {
            Diagnostics::ERROR => '[!]',
            Diagnostics::WARN  => '[~]',
            default            => '[+]',
        };
    }
}
