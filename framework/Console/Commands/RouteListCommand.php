<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Console\Commands;

use Rsgrinko\Proton\Console\Command;
use Rsgrinko\Proton\Http\Kernel;

/**
 * карта адресов.
 */
final class RouteListCommand extends Command
{
    public function name(): string
    {
        return 'route:list';
    }

    public function description(): string
    {
        return 'показать все маршруты приложения';
    }

    public function usage(): string
    {
        return 'route:list [строка для поиска]';
    }

    public function run(): int
    {
        $needle = mb_strtolower($this->arg(0));
        $rows   = [];

        foreach ((new Kernel())->router()->routes() as $route) {
            $line = implode('|', $route->methods) . ' ' . $route->pattern . ' ' . (string) $route->name;

            if ($needle !== '' && !str_contains(mb_strtolower($line), $needle)) {
                continue;
            }

            $handler = is_array($route->handler)
                ? $this->shortClass((string) $route->handler[0]) . '::' . (string) $route->handler[1]
                : 'функция';

            $rows[] = [
                implode('|', $route->methods),
                $route->pattern,
                $handler,
                implode(', ', $route->middleware),
                (string) $route->name,
            ];
        }

        if ($rows === []) {
            $this->line('Ничего не нашлось');

            return 0;
        }

        $this->table(['Метод', 'Путь', 'Обработчик', 'Прослойки', 'Имя'], $rows);
        $this->line('');
        $this->line('Всего: ' . count($rows));

        return 0;
    }

    private function shortClass(string $class): string
    {
        $short = strrchr($class, '\\');

        return $short === false ? $class : substr($short, 1);
    }
}
