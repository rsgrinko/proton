<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Console\Commands;

use Rsgrinko\Proton\Console\Command;
use Rsgrinko\Proton\Console\DaemonCommand;

/**
 * попросить демон (DaemonCommand) выйти.
 */
final class DaemonStopCommand extends Command
{
    public function name(): string
    {
        return 'daemon:stop';
    }

    public function usage(): string
    {
        return 'daemon:stop <имя команды>';
    }

    public function description(): string
    {
        return 'демон доработает круг и выйдет (служба поднимет его заново)';
    }

    public function run(): int
    {
        $name = $this->arg(0);

        if ($name === '') {
            $this->fail('Укажите имя команды-демона: ' . $this->usage());

            return 1;
        }

        // Флаг, а не сигнал: на Windows фоновому процессу сигнал не послать,
        // а одинаковый способ на обеих системах проще помнить
        DaemonCommand::requestStop($name);

        $this->ok('Запрос отправлен. Демон ' . $name . ' выйдет после текущего круга');

        return 0;
    }
}
