<?php

declare(strict_types=1);

/**
 * Наблюдатели моделей — реестр и вызов методов из жизненного цикла Model.
 * Реальный наблюдатель приложения (App\Observers\NoteObserver) проверен там,
 * где он и работает — tests/FilesTest.php.
 */

use App\Models\Note;
use Rsgrinko\Proton\Database\Model\Factory;
use Rsgrinko\Proton\Database\Model\Observers;
use Rsgrinko\Proton\Models\User;

test('наблюдатель: «до»-метод может отменить сохранение', function (): void {
    withOwnDatabase(static function (): void {
        Observers::reset();

        $observer = new class {
            public function creating(Note $note): bool
            {
                return trim((string) $note->raw('title')) !== 'запрещено';
            }
        };

        Observers::register(Note::class, $observer::class);

        $blocked = new Note(['title' => 'запрещено', 'body' => '']);
        $blocked->setAttribute('user_id', 1);

        assertFalse($blocked->save(), 'наблюдатель отменил создание');
        assertFalse($blocked->existsInDatabase());

        $allowed = new Note(['title' => 'обычная', 'body' => '']);
        $allowed->setAttribute('user_id', 1);

        assertTrue($allowed->save(), 'наблюдатель пропустил обычную запись');
        assertTrue($allowed->existsInDatabase());

        $allowed->forceDelete();

        Observers::reset();
    });
});

test('наблюдатель: «после»-метод получает force при forceDelete', function (): void {
    withOwnDatabase(static function (): void {
        Observers::reset();

        $observer = new class {
            public static array $seen = [];

            public function deleted(Note $note, bool $force = false): void
            {
                self::$seen[] = $force;
            }
        };

        $observerClass = $observer::class;

        Observers::register(Note::class, $observerClass);

        /** @var User $owner */
        $owner = Factory::of(User::class)->create();
        /** @var Note $note */
        $note = Factory::of(Note::class)->create(['user_id' => $owner->id()]);

        $note->delete();
        $note->forceDelete();

        assertSame([false, true], $observerClass::$seen, 'мягкое и насовсем различимы по force');

        $owner->forceDelete();

        Observers::reset();
    });
});

test('наблюдатель: без регистрации модель работает как обычно', function (): void {
    withOwnDatabase(static function (): void {
        Observers::reset();

        assertNull(Observers::for(User::class));

        /** @var User $user */
        $user = Factory::of(User::class)->create();

        assertTrue($user->existsInDatabase());

        $user->forceDelete();

        Observers::reset();
    });
});
