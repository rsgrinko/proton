<?php

declare(strict_types=1);

/**
 * Модели: заполнение, приведение типов, связи, мягкое удаление.
 */

use App\Models\Note;
use Rsgrinko\Proton\Database\Model\RecordNotFound;
use Rsgrinko\Proton\Models\Role;
use Rsgrinko\Proton\Models\User;

/**
 * Пользователь для тестов моделей: заводим одного на файл и убираем в конце.
 */
function modelTestUser(): User
{
    static $user = null;

    if ($user instanceof User) {
        return $user;
    }

    $user = User::register('model_test_' . bin2hex(random_bytes(3)), 'секрет123', [
        'email'   => 'model' . bin2hex(random_bytes(3)) . '@example.com',
        'role_id' => Role::admin()?->id() ?? 0,
    ]);

    afterTests(static function () use ($user): void {
        Note::query()->withTrashed()->where('user_id', $user->id())->forceDelete();
        $user->forceDelete();
    });

    return $user;
}

test('модель: сохранение, чтение и отметки времени', function (): void {
    $note = Note::create(['title' => 'Заголовок', 'body' => 'Текст']);

    $note->forceFill(['user_id' => modelTestUser()->id()])->save();

    assertTrue($note->id() > 0, 'должен появиться номер записи');
    assertSame('zagolovok', (string) $note->raw('slug'), 'адресная часть считается по названию');
    assertTrue(trim((string) $note->raw('created_at')) !== '', 'отметка создания проставляется сама');

    $found = assertNotNull(Note::find($note->id()));

    assertSame('Заголовок', (string) $found->title);
});

test('модель: массово заполняются только разрешённые поля', function (): void {
    $note = new Note(['title' => 'Чужое поле', 'user_id' => 999999]);

    // user_id не в белом списке — форма не должна назначать чужого владельца
    assertNull($note->raw('user_id'));

    $note->setAttribute('user_id', modelTestUser()->id());
    $note->save();

    assertSame(modelTestUser()->id(), (int) $note->raw('user_id'));
});

test('модель: пустой $fillable не пускает ничего', function (): void {
    // Забытый список не должен открывать все колонки разом: fill() и update()
    // у такой модели отбрасывают всё, заполнить можно только forceFill()
    $model = new class (['user_id' => 999999, 'title' => 'Из формы']) extends Rsgrinko\Proton\Database\Model\Model {
        protected static string $table = 'notes';
    };

    assertNull($model->raw('user_id'));
    assertNull($model->raw('title'));

    $model->forceFill(['title' => 'Своим кодом']);

    assertSame('Своим кодом', $model->raw('title'));
});

test('модель: приведение типов работает в обе стороны', function (): void {
    $note = Note::create(['title' => 'Типы', 'pinned' => 1]);

    $note->forceFill(['user_id' => modelTestUser()->id()])->save();

    $fresh = assertNotNull(Note::find($note->id()));

    assertTrue($fresh->pinned === true, 'pinned объявлен как bool');
    assertTrue(is_int($fresh->user_id), 'user_id объявлен как int');
});

test('модель: скрытые поля не уезжают наружу', function (): void {
    $data = modelTestUser()->toArray();

    assertFalse(array_key_exists('password_hash', $data), 'хеш пароля в массиве быть не должен');
    assertTrue(array_key_exists('login', $data));
});

test('модель: изменения считаются, и лишнего в UPDATE не уходит', function (): void {
    $note = Note::create(['title' => 'Было']);

    $note->forceFill(['user_id' => modelTestUser()->id()])->save();

    assertFalse($note->isDirty(), 'сразу после сохранения менять нечего');

    $note->title = 'Стало';

    $changes = $note->changes();

    assertTrue(array_key_exists('title', $changes));
    assertFalse(array_key_exists('body', $changes), 'нетронутое поле в изменения не попадает');

    $note->save();

    assertSame('Стало', (string) assertNotNull(Note::find($note->id()))->title);
});

test('модель: мягкое удаление прячет запись, но не теряет её', function (): void {
    $note = Note::create(['title' => 'Удалённая']);

    $note->forceFill(['user_id' => modelTestUser()->id()])->save();

    $id = $note->id();

    $note->delete();

    assertNull(Note::find($id), 'из обычной выборки запись пропала');
    assertNotNull(Note::query()->withTrashed()->where('id', $id)->first(), 'но в базе осталась');

    $trashed = assertNotNull(Note::query()->withTrashed()->where('id', $id)->first());

    assertTrue($trashed->trashed());

    $trashed->restore();

    assertNotNull(Note::find($id), 'после восстановления запись снова видна');
});

test('модель: forceDelete() запроса бьёт только по своей области, а не по всей таблице', function (): void {
    $user = modelTestUser();

    $alive   = Note::create(['title' => 'Живая']);
    $trashed = Note::create(['title' => 'В корзине']);

    $alive->forceFill(['user_id' => $user->id()])->save();
    $trashed->forceFill(['user_id' => $user->id()])->save();

    $trashed->delete();

    // onlyTrashed()->forceDelete() без своего where() не должен цеплять живые
    // записи — раньше цеплял: у forceDelete() область мягкого удаления
    // не применялась вовсе
    Note::query()->where('user_id', $user->id())->onlyTrashed()->forceDelete();

    assertNotNull(Note::find($alive->id()), 'живая запись осталась');
    assertNull(Note::query()->withTrashed()->where('id', $trashed->id())->first(), 'удалённая пропала насовсем');

    $alive->forceDelete();
});

test('модель: findOrFail бросает понятное исключение', function (): void {
    $error = assertThrows(static function (): void {
        Note::findOrFail(987654321);
    });

    assertTrue($error instanceof RecordNotFound);
});

test('связи: заметки автора и автор заметки', function (): void {
    $user = modelTestUser();

    $first = Note::create(['title' => 'Связь один']);
    $first->forceFill(['user_id' => $user->id()])->save();

    $second = Note::create(['title' => 'Связь два']);
    $second->forceFill(['user_id' => $user->id()])->save();

    $author = assertNotNull($first->author);

    assertSame($user->id(), $author->id());

    $notes = Note::query()->where('user_id', $user->id())->get();

    assertTrue(count($notes) >= 2, 'у автора должны быть его заметки');
});

test('модель: cursor() отдаёт модели по одной', function (): void {
    $user = modelTestUser();

    $marker = 'cursor_' . bin2hex(random_bytes(4));

    foreach (['раз', 'два', 'три'] as $suffix) {
        Note::create(['title' => $marker . '_' . $suffix])->forceFill(['user_id' => $user->id()])->save();
    }

    $query = Note::query()->where('user_id', $user->id())->whereLike('title', $marker)->orderBy('id');

    $titles = [];

    foreach ($query->cursor() as $note) {
        assertTrue($note instanceof Note, 'cursor() отдаёт модели, а не сырые строки');

        $titles[] = (string) $note->title;
    }

    assertSame([$marker . '_раз', $marker . '_два', $marker . '_три'], $titles);
});

test('связи: подгрузка через with() не ходит за каждой строкой', function (): void {
    $user = modelTestUser();

    $note = Note::create(['title' => 'С подгрузкой']);
    $note->forceFill(['user_id' => $user->id()])->save();

    $loaded = Note::query()->with('author')->where('user_id', $user->id())->get();

    assertTrue($loaded !== [], 'заметки должны найтись');
    assertTrue($loaded[0]->relationLoaded('author'), 'связь должна быть подгружена заранее');
    assertSame($user->id(), assertNotNull($loaded[0]->author)->id());
});

test('роли: у встроенной роли права берутся из кода', function (): void {
    $admin = assertNotNull(Role::admin());

    assertTrue($admin->isSystem());
    assertTrue(in_array('data.all', $admin->permissions(), true), 'администратор видит чужие записи');
    assertTrue(in_array('notes.view', $admin->permissions(), true), 'право приложения тоже должно попасть');
});
