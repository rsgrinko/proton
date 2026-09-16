<?php

declare(strict_types=1);

/**
 * Комментарии: обсуждение к любой записи.
 */

use App\Models\Note;
use Rsgrinko\Proton\Comments\Comment;
use Rsgrinko\Proton\Database\Model\Factory;
use Rsgrinko\Proton\Models\User;

test('комментарии: добавляются к записи и читаются по порядку', function (): void {
    withOwnDatabase(static function (): void {
        /** @var User $author */
        $author = Factory::of(User::class)->create();
        /** @var Note $note */
        $note = Factory::of(Note::class)->create(['user_id' => $author->id()]);

        $first  = Comment::add('note', $note->id(), $author->id(), 'Первый');
        $second = Comment::add('note', $note->id(), $author->id(), '  Второй  ');

        assertSame('Второй', $second->body, 'пробелы по краям обрезаются');

        $found = Comment::of('note', $note->id());

        assertCount(2, $found);
        assertSame($first->id(), $found[0]->id(), 'старые сверху');
        assertSame($second->id(), $found[1]->id());

        assertSame(2, Comment::countOf('note', $note->id()));
        assertSame(0, Comment::countOf('note', $note->id() + 1), 'у чужой записи комментариев нет');
    });
});

test('комментарии: знают своего автора', function (): void {
    withOwnDatabase(static function (): void {
        /** @var User $author */
        $author = Factory::of(User::class)->create();
        /** @var Note $note */
        $note = Factory::of(Note::class)->create(['user_id' => $author->id()]);

        $comment = Comment::add('note', $note->id(), $author->id(), 'Текст');

        assertSame($author->id(), $comment->author?->id());
    });
});

test('комментарии: удаление записи разом убирает все её комментарии', function (): void {
    withOwnDatabase(static function (): void {
        /** @var User $author */
        $author = Factory::of(User::class)->create();
        /** @var Note $note */
        $note = Factory::of(Note::class)->create(['user_id' => $author->id()]);

        Comment::add('note', $note->id(), $author->id(), 'Раз');
        Comment::add('note', $note->id(), $author->id(), 'Два');

        assertSame(2, Comment::removeAll('note', $note->id()));
        assertSame(0, Comment::countOf('note', $note->id()));
    });
});
