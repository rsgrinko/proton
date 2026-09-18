<?php

declare(strict_types=1);

/**
 * Консольные команды приложения. Классы из app/Commands перечисляются здесь —
 * иначе они не появятся ни в справке, ни в разборе аргументов.
 *
 * Создать заготовку: php bin/proton make:command Имя
 */

use App\Commands\NotesPinnedReportCommand;
use App\Commands\NotesSeedCommand;

return [
    // Пример своей команды — заводит демо-заметки для проверки списка
    NotesSeedCommand::class,

    // Пример своей цепочки задач очереди (Queue::chain()) — см. класс команды
    NotesPinnedReportCommand::class,
];
