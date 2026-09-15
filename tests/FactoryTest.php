<?php

declare(strict_types=1);

/**
 * Фабрики моделей и помощники HTTP-тестов.
 */

use App\Models\Note;
use Rsgrinko\Proton\Database\Model\Factory;
use Rsgrinko\Proton\Models\User;
use Rsgrinko\Proton\Support\ProtonException;

test('фабрика: делает запись и разводит уникальные поля', function (): void {
    withOwnDatabase(static function (): void {
        /** @var User $first */
        $first = Factory::of(User::class)->create();
        /** @var User $second */
        $second = Factory::of(User::class)->create();

        assertTrue($first->id() > 0);
        assertNotContains((string) $first->login, (string) $second->login, 'логины должны отличаться');
        assertTrue((string) $first->email !== (string) $second->email);
        assertSame(2, User::query()->count());
    });
});

test('фабрика: times делает список, state и аргументы перебивают заготовку', function (): void {
    withOwnDatabase(static function (): void {
        /** @var User $owner */
        $owner = Factory::of(User::class)->create();

        $notes = Factory::of(Note::class)
            ->times(3)
            ->state(['user_id' => $owner->id()])
            ->create(['pinned' => 1]);

        assertCount(3, $notes);
        assertSame(3, Note::query()->where('user_id', $owner->id())->where('pinned', 1)->count());

        // make() ничего не пишет в базу
        $draft = Factory::of(Note::class)->make(['title' => 'Черновик']);

        assertSame('Черновик', (string) $draft->title);
        assertSame(3, Note::query()->count(), 'make не создаёт запись');
    });
});

test('фабрика: без описания говорит, где его завести', function (): void {
    $error = assertThrows(static fn () => Factory::of(Rsgrinko\Proton\Models\AuditEntry::class));

    assertTrue($error instanceof ProtonException);
    assertContains('database/factories.php', $error->getMessage());
});

test('помощники: actingAs и get работают вместе с фабрикой', function (): void {
    $user = Factory::of(User::class)->create();

    afterTests(static function () use ($user): void {
        Note::query()->withTrashed()->where('user_id', $user->id())->forceDelete();
        $user->forceDelete();
    });

    actingAs($user);

    $response = get('/notes');

    assertStatus(200, $response);
    assertSee('Заметки', $response);

    // Гостя уводит на вход
    actingAs(null);

    assertRedirect('/login', get('/'));
});

test('помощники: post подставляет токен формы, assertDontSee ловит лишнее', function (): void {
    $user = Factory::of(User::class)->create();

    afterTests(static function () use ($user): void {
        Note::query()->withTrashed()->where('user_id', $user->id())->forceDelete();
        $user->forceDelete();
    });

    actingAs($user);

    $response = post('/notes/new', ['title' => 'Из помощника', 'body' => 'текст']);

    assertRedirect('/notes', $response);

    $note = assertNotNull(Note::query()->where('user_id', $user->id())->first());

    assertSame('Из помощника', (string) $note->title);

    $list = get('/notes');

    assertSee('Из помощника', $list);
    assertDontSee('Такой заметки нет', $list);

    actingAs(null);
});
