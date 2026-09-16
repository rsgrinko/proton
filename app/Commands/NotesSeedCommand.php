<?php

declare(strict_types=1);

namespace App\Commands;

use App\Models\Note;
use Rsgrinko\Proton\Console\Command;
use Rsgrinko\Proton\Models\User;
use Rsgrinko\Proton\Support\Str;

/**
 * Пример своей консольной команды: заводит демонстрационные заметки для
 * проверки списка — на пустой базе не увидеть ни страниц, ни фильтров,
 * ни поиска.
 *
 * Класс лежит в app/Commands, а появляется в справке только вместе со строкой
 * в config/commands.php — забытая регистрация выглядит как «команды нет».
 *
 *     php bin/proton notes:seed ivan --count=30
 */
final class NotesSeedCommand extends Command
{
    public function name(): string
    {
        return 'notes:seed';
    }

    public function description(): string
    {
        return 'завести демонстрационные заметки для пользователя (пример своей команды)';
    }

    public function usage(): string
    {
        return 'notes:seed <логин> [--count=10]';
    }

    public function run(): int
    {
        $login = trim($this->arg(0));

        if ($login === '') {
            $this->fail('Укажите логин: php bin/proton notes:seed ivan');

            return 1;
        }

        $user = User::findByCredential($login);

        if ($user === null) {
            $this->fail('Пользователь не найден: ' . $login);

            return 1;
        }

        $count = max(1, (int) $this->option('count', '10'));
        $rows  = [];

        for ($i = 1; $i <= $count; $i++) {
            // create() заполняет только fillable — user_id туда нарочно не входит
            // (форма не должна отдавать чужие заметки), поэтому его выставляют отдельно
            $note = Note::create([
                'title' => 'Демо-заметка ' . Str::random(6),
                'body'  => 'Заведена командой notes:seed для проверки списка.',
            ]);

            $note->forceFill(['user_id' => $user->id()])->save();

            $rows[] = [(string) $note->id(), (string) $note->title];
        }

        $this->table(['id', 'Заголовок'], $rows);
        $this->ok('Заведено заметок: ' . $count . ' для ' . $login);

        return 0;
    }
}
