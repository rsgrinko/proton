<?php

declare(strict_types=1);

/**
 * Стартовые данные для разработки: `php bin/proton seed` или
 * `php bin/proton migrate:fresh --seed`.
 *
 * Роли и первый администратор заводятся установщиком, поэтому здесь только
 * то, на чём удобно смотреть списки: несколько пользователей и заметки.
 * На боевой базе это не запускают.
 */

use App\Models\Note;
use Rsgrinko\Proton\Database\Model\Factory;
use Rsgrinko\Proton\Models\User;

return static function (callable $say): void {
    $users = User::query()->count();

    if ($users === 0) {
        $say('Пользователей нет — сначала php bin/proton install');

        return;
    }

    /** @var array<int, User> $people */
    $people = Factory::of(User::class)->times(5)->create();

    $say('Заведено пользователей: ' . count($people));

    $notes = 0;

    foreach ($people as $person) {
        // Заметки раскладываем по людям: так видно, что область видимости работает
        Factory::of(Note::class)
            ->times(random_int(2, 6))
            ->state(['user_id' => $person->id()])
            ->create();

        $notes += Note::query()->where('user_id', $person->id())->count();
    }

    $say('Заведено заметок: ' . $notes);
};
