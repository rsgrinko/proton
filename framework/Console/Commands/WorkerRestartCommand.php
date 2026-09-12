<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Console\Commands;

use Rsgrinko\Proton\Console\Command;
use Rsgrinko\Proton\Queue\Worker;

/**
 * попросить воркер перезапуститься.
 */
final class WorkerRestartCommand extends Command
{
    public function name(): string
    {
        return 'worker:restart';
    }

    public function description(): string
    {
        return 'воркер доработает круг и выйдет (служба поднимет его заново)';
    }

    public function run(): int
    {
        Worker::requestRestart();

        // После выкладки кода это обязательный шаг: воркер держит классы
        // в памяти и продолжал бы выполнять задачи старым кодом
        $this->ok('Запрос отправлен. Воркер выйдет после текущего круга');

        return 0;
    }
}
