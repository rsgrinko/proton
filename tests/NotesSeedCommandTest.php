<?php

declare(strict_types=1);

/**
 * notes:seed — пример своей консольной команды (см. app/Commands/NotesSeedCommand.php
 * и config/commands.php).
 */

use App\Commands\NotesSeedCommand;
use App\Models\Note;
use Rsgrinko\Proton\Models\Role;
use Rsgrinko\Proton\Models\User;

test('notes:seed заводит нужное число заметок для указанного пользователя', function (): void {
    $user = User::register('notes_seed_' . bin2hex(random_bytes(3)), 'секрет123', [
        'email'   => 'notes_seed' . bin2hex(random_bytes(3)) . '@example.com',
        'role_id' => Role::admin()?->id() ?? 0,
    ]);

    afterTests(static function () use ($user): void {
        Note::query()->withTrashed()->where('user_id', $user->id())->forceDelete();
        $user->forceDelete();
    });

    $out = '';

    $status = (new NotesSeedCommand())
        ->withInput([(string) $user->login], ['count' => '3'], static function (string $line) use (&$out): void {
            $out .= $line . "\n";
        })
        ->run();

    assertSame(0, $status);
    assertContains('Заведено заметок: 3', $out);
    assertSame(3, Note::query()->where('user_id', $user->id())->count());
});

test('notes:seed без пользователя отвечает понятной ошибкой, а не падением', function (): void {
    $status = (new NotesSeedCommand())
        ->withInput(['нет_такого_логина_' . bin2hex(random_bytes(4))], [], static function (): void {})
        ->run();

    assertSame(1, $status);
});

test('команда зарегистрирована и видна в списке команд', function (): void {
    assertTrue(in_array(
        NotesSeedCommand::class,
        array_merge(...array_values(Rsgrinko\Proton\Console\Application::groups())),
        true
    ));
});
