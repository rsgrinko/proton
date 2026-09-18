<?php

declare(strict_types=1);

namespace App\Commands;

use App\Jobs\CountPinnedNotesJob;
use App\Jobs\NotifyPinnedNotesCountJob;
use Rsgrinko\Proton\Console\Command;
use Rsgrinko\Proton\Models\User;
use Rsgrinko\Proton\Queue\Queue;

/**
 * Пример своей цепочки задач (`Queue::chain()`) — образец на замену, если
 * работа распадается на «посчитать/подготовить» и «сообщить об итоге»
 * отдельными шагами. Тот же приём, что у фоновой выгрузки списка
 * (`Controller::exportCsv()` -> ExportJob -> NotifyExportReadyJob), но на
 * своих классах и без похода в ядро.
 *
 * Сама команда только ставит цепочку в очередь — посчитает и известит воркер:
 *
 *     php bin/proton notes:pinned-report ivan
 *     php bin/proton worker --once
 */
final class NotesPinnedReportCommand extends Command
{
    public function name(): string
    {
        return 'notes:pinned-report';
    }

    public function description(): string
    {
        return 'посчитать закреплённые заметки пользователя в очереди и известить его (пример Queue::chain())';
    }

    public function usage(): string
    {
        return 'notes:pinned-report <логин>';
    }

    public function run(): int
    {
        $login = trim($this->arg(0));

        if ($login === '') {
            $this->fail('Укажите логин: php bin/proton notes:pinned-report ivan');

            return 1;
        }

        $user = User::findByCredential($login);

        if ($user === null) {
            $this->fail('Пользователь не найден: ' . $login);

            return 1;
        }

        // Второй шаг своего payload не получает вовсе — id и count ему
        // передаст carryToNext() первого шага, когда результат появится
        Queue::chain([
            [CountPinnedNotesJob::class, ['user_id' => $user->id()]],
            [NotifyPinnedNotesCountJob::class],
        ]);

        $this->ok('Цепочка поставлена в очередь, разберёт воркер: ' . $login);

        return 0;
    }
}
