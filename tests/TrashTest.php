<?php

declare(strict_types=1);

/**
 * Корзина, массовые действия и история записи.
 */

use App\Models\Note;
use Rsgrinko\Proton\Database\Model\Factory;
use Rsgrinko\Proton\Models\AuditEntry;
use Rsgrinko\Proton\Models\Role;
use Rsgrinko\Proton\Models\User;
use Rsgrinko\Proton\Support\Trash;

test('корзина: удалённая запись пропадает из списков и возвращается обратно', function (): void {
    withOwnDatabase(static function (): void {
        /** @var User $user */
        $user = Factory::of(User::class)->create();

        $id = $user->id();

        $user->delete();

        assertNull(User::find($id), 'обычный поиск удалённого не видит');
        assertSame(0, User::query()->count());
        assertSame(1, User::query()->onlyTrashed()->count(), 'а в корзине он есть');
        assertSame(1, Trash::query('users')?->count());

        /** @var User $deleted */
        $deleted = assertNotNull(User::query()->onlyTrashed()->where('id', $id)->first());

        $deleted->restore();

        assertNotNull(User::find($id), 'вернулся');
        assertSame(0, Trash::query('users')?->count());
    });
});

test('корзина: считает по разделам и добивает слишком старое', function (): void {
    withOwnDatabase(static function (): void {
        /** @var User $owner */
        $owner = Factory::of(User::class)->create();

        /** @var Note $fresh */
        $fresh = Factory::of(Note::class)->create(['user_id' => $owner->id()]);
        /** @var Note $old */
        $old = Factory::of(Note::class)->create(['user_id' => $owner->id()]);

        $fresh->delete();
        $old->delete();

        // Одну «удалили» месяц назад
        $old->forceFill(['deleted_at' => date('Y-m-d H:i:s', time() - 40 * 86400)])->save();

        $counts = Trash::counts();

        assertSame(2, $counts['notes']);
        assertSame(0, $counts['users']);

        assertSame(1, Trash::purge(30), 'старое добито');
        assertSame(1, Trash::counts()['notes'], 'свежее осталось');

        // Ноль дней — уборка не трогает ничего
        assertSame(0, Trash::purge(0));
    });
});

test('корзина: раздел показывает удалённое и возвращает его кнопкой', function (): void {
    withOwnDatabase(static function (): void {
        /** @var User $admin */
        $admin = Factory::of(User::class)->create(['role_id' => Role::admin()?->id() ?? 0]);
        /** @var User $victim */
        $victim = Factory::of(User::class)->create();

        $victim->delete();

        actingAs($admin);

        $response = get('/admin/trash', ['kind' => 'users']);

        assertStatus(200, $response);
        assertSee((string) $victim->login, $response);

        assertRedirect('/admin/trash', post('/admin/trash/restore', ['kind' => 'users', 'id' => $victim->id()]));
        assertNotNull(User::find($victim->id()), 'запись вернулась');

        // И добить окончательно
        $victim->delete();

        post('/admin/trash/destroy', ['kind' => 'users', 'id' => $victim->id()]);

        assertSame(0, User::query()->withTrashed()->where('id', $victim->id())->count(), 'записи больше нет');

        actingAs(null);
    });
});

test('массовые действия: выключают отмеченных и не трогают того, кто нажал', function (): void {
    withOwnDatabase(static function (): void {
        /** @var User $admin */
        $admin = Factory::of(User::class)->create(['role_id' => Role::admin()?->id() ?? 0]);
        /** @var array<int, User> $people */
        $people = Factory::of(User::class)->times(3)->create();

        actingAs($admin);

        $ids = array_map(static fn (User $user): int => $user->id(), $people);

        assertRedirect('/admin/users', post('/admin/users/bulk', [
            'action' => 'disable',
            'ids'    => [...$ids, $admin->id()],
        ]));

        foreach ($ids as $id) {
            assertFalse((bool) User::find($id)?->isActive(), 'отмеченный выключен');
        }

        assertTrue((bool) User::find($admin->id())?->isActive(), 'себя массовое действие не трогает');

        // Удаление уводит в корзину
        post('/admin/users/bulk', ['action' => 'delete', 'ids' => $ids]);

        assertSame(3, User::query()->onlyTrashed()->count());

        actingAs(null);
    });
});

test('массовые действия: без отметок ничего не делают', function (): void {
    withOwnDatabase(static function (): void {
        /** @var User $admin */
        $admin = Factory::of(User::class)->create(['role_id' => Role::admin()?->id() ?? 0]);

        actingAs($admin);

        post('/admin/users/bulk', ['action' => 'delete', 'ids' => []]);

        assertSame(1, User::query()->count(), 'никого не удалили');

        actingAs(null);
    });
});

test('корзина: массово возвращает и добивает отмеченные', function (): void {
    withOwnDatabase(static function (): void {
        /** @var User $admin */
        $admin = Factory::of(User::class)->create(['role_id' => Role::admin()?->id() ?? 0]);
        /** @var array<int, User> $people */
        $people = Factory::of(User::class)->times(3)->create();

        foreach ($people as $person) {
            $person->delete();
        }

        actingAs($admin);

        $ids = array_map(static fn (User $user): int => $user->id(), $people);

        post('/admin/trash/bulk', ['kind' => 'users', 'action' => 'restore', 'ids' => array_slice($ids, 0, 2)]);

        assertSame(1, User::query()->onlyTrashed()->count(), 'два вернулись, один остался в корзине');

        post('/admin/trash/bulk', ['kind' => 'users', 'action' => 'destroy', 'ids' => array_slice($ids, 2, 1)]);

        assertSame(0, Trash::query('users')?->count(), 'в корзине никого не осталось');
        assertSame(3, User::query()->count(), 'админ и два возвращённых на месте');

        actingAs(null);
    });
});

test('корзина: «очистить» убирает весь раздел разом, а не только страницу', function (): void {
    withOwnDatabase(static function (): void {
        /** @var User $admin */
        $admin = Factory::of(User::class)->create(['role_id' => Role::admin()?->id() ?? 0]);
        /** @var array<int, User> $people */
        $people = Factory::of(User::class)->times(5)->create();

        foreach ($people as $person) {
            $person->delete();
        }

        actingAs($admin);

        assertSame(5, Trash::query('users')?->count());

        assertRedirect('/admin/trash', post('/admin/trash/clear', ['kind' => 'users']));

        assertSame(0, Trash::query('users')?->count(), 'корзина пуста целиком');
        assertSame(1, User::query()->withTrashed()->count(), 'остался только админ');

        actingAs(null);
    });
});

test('история: карточка показывает записи журнала по этой записи', function (): void {
    withOwnDatabase(static function (): void {
        /** @var User $admin */
        $admin = Factory::of(User::class)->create(['role_id' => Role::admin()?->id() ?? 0]);

        actingAs($admin);

        // Правка пишется в журнал — она и должна появиться в истории
        post('/admin/users/' . $admin->id(), [
            'email'   => 'novy@example.com',
            'name'    => 'Новое имя',
            'role_id' => (string) $admin->raw('role_id'),
            'active'  => '1',
        ]);

        assertTrue(AuditEntry::query()->where('entity', 'user')->where('entity_id', (string) $admin->id())->count() > 0);

        $response = get('/admin/users/' . $admin->id());

        assertStatus(200, $response);
        assertSee('История', $response);
        assertSee('изменён пользователь', $response);

        actingAs(null);
    });
});
