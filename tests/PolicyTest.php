<?php

declare(strict_types=1);

/**
 * Правила на запись и подписанные ссылки.
 */

use App\Models\Note;
use Rsgrinko\Proton\Access\Policy;
use Rsgrinko\Proton\Access\Viewer;
use Rsgrinko\Proton\Database\Model\Factory;
use Rsgrinko\Proton\Http\Request;
use Rsgrinko\Proton\Models\Role;
use Rsgrinko\Proton\Models\User;
use Rsgrinko\Proton\Support\SignedUrl;

/**
 * Пользователь с ролью администратора — ему видно всё.
 */
function policyAdmin(): User
{
    static $admin = null;

    if ($admin instanceof User) {
        return $admin;
    }

    $admin = Factory::of(User::class)->create(['role_id' => Role::admin()?->id() ?? 0]);

    afterTests(static function () use ($admin): void {
        Note::query()->withTrashed()->where('user_id', $admin->id())->forceDelete();
        $admin->forceDelete();
    });

    return $admin;
}

test('правила: свою заметку правит владелец, чужую — только полный доступ', function (): void {
    $owner    = Factory::of(User::class)->create();
    $stranger = Factory::of(User::class)->create();

    /** @var Note $note */
    $note = Factory::of(Note::class)->create(['user_id' => $owner->id()]);

    afterTests(static function () use ($owner, $stranger): void {
        Note::query()->withTrashed()->where('user_id', $owner->id())->forceDelete();
        $owner->forceDelete();
        $stranger->forceDelete();
    });

    assertTrue(Policy::allows('note.edit', $note, Viewer::fromUser($owner)), 'владелец правит своё');
    assertFalse(Policy::allows('note.edit', $note, Viewer::fromUser($stranger)), 'чужой не правит');
    assertTrue(Policy::allows('note.edit', $note, Viewer::fromUser(policyAdmin())), 'администратор правит любое');

    // Закрепление строже: его не даёт даже владельцу
    assertFalse(Policy::allows('note.pin', $note, Viewer::fromUser($owner)));
    assertTrue(Policy::allows('note.pin', $note, Viewer::fromUser(policyAdmin())));
});

test('правила: без правила решает право с тем же именем', function (): void {
    $user = Factory::of(User::class)->create();

    afterTests(static fn () => $user->forceDelete());

    assertFalse(Policy::has('users.manage'), 'на это правила нет');
    assertFalse(Policy::allows('users.manage', null, Viewer::fromUser($user)));
    assertTrue(Policy::allows('users.manage', null, Viewer::fromUser(policyAdmin())));
});

test('правила: чужую заметку нельзя открыть на правку и через адрес', function (): void {
    $owner    = Factory::of(User::class)->create();
    $stranger = Factory::of(User::class)->create(['role_id' => Role::admin()?->id() ?? 0]);

    /** @var Note $note */
    $note = Factory::of(Note::class)->create(['user_id' => $owner->id()]);

    afterTests(static function () use ($owner, $stranger): void {
        Note::query()->withTrashed()->where('user_id', $owner->id())->forceDelete();
        $owner->forceDelete();
        $stranger->forceDelete();
    });

    // Администратору чужая заметка видна и доступна
    actingAs($stranger);

    assertStatus(200, get('/notes/' . $note->id() . '/edit'));

    // А обычному чужаку — нет: область видимости не отдаёт запись вовсе
    $other = Factory::of(User::class)->create();

    afterTests(static fn () => $other->forceDelete());

    actingAs($other);

    assertRedirect('/notes', get('/notes/' . $note->id() . '/edit'), 'чужая запись не существует');

    actingAs(null);
});

test('подпись: ссылка работает, но не переживает подделку и срок', function (): void {
    $owner = Factory::of(User::class)->create();

    /** @var Note $note */
    $note = Factory::of(Note::class)->create(['user_id' => $owner->id()]);

    afterTests(static function () use ($owner): void {
        Note::query()->withTrashed()->where('user_id', $owner->id())->forceDelete();
        $owner->forceDelete();
    });

    $url  = SignedUrl::to('notes.file.shared', ['id' => $note->id()], 3600);
    $path = (string) parse_url($url, PHP_URL_PATH);

    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    // Подпись цела — ссылка действительна даже для гостя
    assertTrue(SignedUrl::valid(Request::create('GET', $path, [], $query)));

    // Подменили id — подпись не сходится
    $tampered        = $query;
    $tampered['id']  = (string) ($note->id() + 1);

    assertFalse(SignedUrl::valid(Request::create('GET', $path, [], $tampered)));

    // Протухшая ссылка не работает
    $expired = SignedUrl::to('notes.file.shared', ['id' => $note->id()], 1);

    parse_str((string) parse_url($expired, PHP_URL_QUERY), $expiredQuery);

    $expiredQuery[SignedUrl::EXPIRES] = (string) (time() - 10);

    assertFalse(SignedUrl::valid(Request::create('GET', $path, [], $expiredQuery)));

    // И совсем без подписи тоже
    assertFalse(SignedUrl::valid(Request::create('GET', $path, [], ['id' => (string) $note->id()])));
});

test('подпись: без неё адрес закрыт, с ней отдаёт файл гостю', function (): void {
    $owner = Factory::of(User::class)->create();

    /** @var Note $note */
    $note = Factory::of(Note::class)->create(['user_id' => $owner->id()]);

    $note->forceFill(['file_path' => '', 'file_name' => ''])->save();

    afterTests(static function () use ($owner): void {
        Note::query()->withTrashed()->where('user_id', $owner->id())->forceDelete();
        $owner->forceDelete();
    });

    actingAs(null);

    // Без подписи — 403 от прослойки
    assertStatus(403, get('/files/notes/' . $note->id()));

    // С подписью прослойка пускает, а дальше уже «файла нет» — значит, адрес
    // открылся гостю
    $url = SignedUrl::to('notes.file.shared', ['id' => $note->id()], 3600);

    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    assertStatus(404, get('/files/notes/' . $note->id(), $query));
});
