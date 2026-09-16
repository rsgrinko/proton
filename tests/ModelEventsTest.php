<?php

declare(strict_types=1);

/**
 * Модельные события: model.saving/creating/updating/deleting/restoring (можно
 * отменить, вернув false) и model.created/updated/saved/deleted/restored (после
 * факта, только уведомление). Работает на любой модели без лишней настройки —
 * проверяем на демонстрационной Note.
 */

use App\Models\Note;
use Rsgrinko\Proton\Events\Events;

test('модель: saving и creating летят при создании, saved и created — после', function (): void {
    $fired = [];

    Events::listen('model.saving', static function (array $payload) use (&$fired): void {
        $fired[] = 'saving:' . ($payload['model']->existsInDatabase() ? 'exists' : 'new');
    });
    Events::listen('model.creating', static function () use (&$fired): void {
        $fired[] = 'creating';
    });
    Events::listen('model.created', static function () use (&$fired): void {
        $fired[] = 'created';
    });
    Events::listen('model.saved', static function () use (&$fired): void {
        $fired[] = 'saved';
    });

    try {
        $note = Note::create(['title' => 'Событие: создание']);

        assertSame(['saving:new', 'creating', 'created', 'saved'], $fired);
    } finally {
        $note->forceDelete();
        Events::reset();
    }
});

test('модель: updating летит только при реальном изменении полей', function (): void {
    $note = Note::create(['title' => 'Без изменений']);

    $fired = [];

    Events::listen('model.updating', static function () use (&$fired): void {
        $fired[] = 'updating';
    });

    try {
        assertTrue($note->save(), 'нечего сохранять — но это не ошибка');
        assertSame([], $fired, 'изменений не было — updating не звался');

        $note->title = 'С изменением';
        $note->save();

        assertSame(['updating'], $fired);
    } finally {
        $note->forceDelete();
        Events::reset();
    }
});

test('модель: слушатель отменяет сохранение, вернув false', function (): void {
    $note = new Note(['title' => 'Отменённая запись']);

    Events::listen('model.creating', static fn (): bool => false);

    try {
        $saved = $note->save();

        assertFalse($saved, 'creating вернул false — запись не создаётся');
        assertFalse($note->existsInDatabase());
    } finally {
        Events::reset();
    }
});

test('модель: deleting может отменить удаление, restoring — восстановление', function (): void {
    $note = Note::create(['title' => 'Удаление под вопросом']);

    Events::listen('model.deleting', static fn (): bool => false);

    try {
        assertFalse($note->delete(), 'deleting вернул false — запись осталась');
        assertFalse($note->trashed());
    } finally {
        Events::reset();
    }

    $note->delete();
    assertTrue($note->trashed());

    Events::listen('model.restoring', static fn (): bool => false);

    try {
        assertFalse($note->restore(), 'restoring вернул false — запись осталась в корзине');
        assertTrue($note->trashed());
    } finally {
        Events::reset();
        $note->forceDelete();
    }
});

test('модель: deleted и restored — после факта, отменить уже нельзя', function (): void {
    $note = Note::create(['title' => 'Полный цикл']);

    $fired = [];

    Events::listen('model.deleted', static function () use (&$fired): void {
        $fired[] = 'deleted';
    });
    Events::listen('model.restored', static function () use (&$fired): void {
        $fired[] = 'restored';
    });

    try {
        $note->delete();
        $note->restore();

        assertSame(['deleted', 'restored'], $fired);
    } finally {
        Events::reset();
        $note->forceDelete();
    }
});
