<?php

declare(strict_types=1);

/**
 * Разделы панели, где смотреть и менять — разные права: у view-роли есть
 * список и карточка, а мутирующие маршруты закрыты, пока не выдали manage
 * (или, у вебхуков, отдельное delete).
 */

use Rsgrinko\Proton\Models\Role;
use Rsgrinko\Proton\Models\User;
use Rsgrinko\Proton\Models\Webhook;

/**
 * Пользователь с ролью из одних только переданных прав.
 */
function permAccessTestUser(array $permissions): User
{
    $role = Role::create([
        'name'        => 'Доступ ' . bin2hex(random_bytes(3)),
        'permissions' => $permissions,
    ]);

    $user = User::register('perm_access_' . bin2hex(random_bytes(3)), 'секрет123', [
        'email'   => 'perm_access' . bin2hex(random_bytes(3)) . '@example.com',
        'role_id' => $role->id(),
    ]);

    afterTests(static function () use ($user, $role): void {
        $user->forceDelete();
        $role->forceDelete();
    });

    return $user;
}

test('права: roles.view смотрит, roles.manage правит', function (): void {
    $viewer = permAccessTestUser(['roles.view']);
    $editor = permAccessTestUser(['roles.manage']);

    assertStatus(200, httpRequest('GET', '/admin/roles', $viewer));
    assertStatus(403, httpRequest('GET', '/admin/roles/new', $viewer));
    assertStatus(403, httpRequest('POST', '/admin/roles/new', $viewer, ['name' => 'x']));

    assertStatus(200, httpRequest('GET', '/admin/roles', $editor));
    assertStatus(200, httpRequest('GET', '/admin/roles/new', $editor));
});

test('права: tokens.view смотрит, tokens.manage выпускает и отзывает', function (): void {
    $viewer = permAccessTestUser(['tokens.view']);
    $editor = permAccessTestUser(['tokens.manage']);

    assertStatus(200, httpRequest('GET', '/admin/tokens', $viewer));
    assertStatus(403, httpRequest('POST', '/admin/tokens', $viewer, ['name' => 'x', 'user_id' => $viewer->id()]));

    assertStatus(200, httpRequest('GET', '/admin/tokens', $editor));
});

test('права: webhooks.view смотрит, webhooks.manage правит, webhooks.delete удаляет — три разных права', function (): void {
    $webhook = Webhook::add('проверка прав ' . bin2hex(random_bytes(3)), 'https://example.com/hook', [Webhook::ALL_EVENTS]);

    afterTests(static function () use ($webhook): void {
        $webhook->forceDelete();
    });

    $viewer = permAccessTestUser(['webhooks.view']);
    $editor = permAccessTestUser(['webhooks.manage']);
    $killer = permAccessTestUser(['webhooks.delete']);

    assertStatus(200, httpRequest('GET', '/admin/webhooks', $viewer));
    assertStatus(200, httpRequest('GET', '/admin/webhooks/' . $webhook->id(), $viewer));
    assertStatus(403, httpRequest('POST', '/admin/webhooks/' . $webhook->id() . '/toggle', $viewer));
    assertStatus(403, httpRequest('POST', '/admin/webhooks/' . $webhook->id() . '/delete', $viewer));

    assertStatus(200, httpRequest('GET', '/admin/webhooks', $editor));
    assertStatus(403, httpRequest('POST', '/admin/webhooks/' . $webhook->id() . '/delete', $editor), 'manage без delete не удаляет');

    assertStatus(200, httpRequest('GET', '/admin/webhooks', $killer), 'delete тоже пускает смотреть список');
    assertStatus(403, httpRequest('POST', '/admin/webhooks/' . $webhook->id() . '/toggle', $killer), 'delete без manage не правит');
});

test('права: backups.view смотрит, backups.manage снимает и удаляет копии', function (): void {
    $viewer = permAccessTestUser(['backups.view']);
    $editor = permAccessTestUser(['backups.manage']);

    assertStatus(200, httpRequest('GET', '/admin/backups', $viewer));
    assertStatus(403, httpRequest('POST', '/admin/backups', $viewer));

    assertStatus(200, httpRequest('GET', '/admin/backups', $editor));
});

test('права: settings.view смотрит, settings.manage правит', function (): void {
    $viewer = permAccessTestUser(['settings.view']);
    $editor = permAccessTestUser(['settings.manage']);

    assertStatus(200, httpRequest('GET', '/admin/settings', $viewer));
    assertStatus(403, httpRequest('POST', '/admin/settings', $viewer, ['shown' => []]));

    assertStatus(200, httpRequest('GET', '/admin/settings', $editor));
});

test('права: security.view смотрит, security.manage закрывает и открывает адреса', function (): void {
    $viewer = permAccessTestUser(['security.view']);
    $editor = permAccessTestUser(['security.manage']);

    assertStatus(200, httpRequest('GET', '/admin/security', $viewer));
    assertStatus(403, httpRequest('POST', '/admin/security/block', $viewer, ['ip' => '203.0.113.5']));

    assertStatus(200, httpRequest('GET', '/admin/security', $editor));
});

test('права: queue.view смотрит, queue.manage повторяет и чистит задачи', function (): void {
    $viewer = permAccessTestUser(['queue.view']);
    $editor = permAccessTestUser(['queue.manage']);

    assertStatus(200, httpRequest('GET', '/admin/queue', $viewer));
    assertStatus(403, httpRequest('POST', '/admin/queue/retry', $viewer, ['ids' => [1]]));

    assertStatus(200, httpRequest('GET', '/admin/queue', $editor));
});

test('права: trash.view добавляет доступ к чужим разделам корзины, но не действия в них', function (): void {
    // У самого по себе users.manage корзина пользователей и так доступна —
    // здесь же смотрим, что именно trash.view/trash.manage дают доступ сами
    // по себе, без прав ни на один конкретный раздел
    $viewer = permAccessTestUser(['trash.view']);
    $editor = permAccessTestUser(['trash.manage']);
    $stranger = permAccessTestUser([]);

    assertStatus(200, httpRequest('GET', '/admin/trash', $viewer));
    assertStatus(403, httpRequest('POST', '/admin/trash/restore', $viewer, ['kind' => 'users', 'id' => 1]));

    assertStatus(200, httpRequest('GET', '/admin/trash', $editor));
    assertStatus(403, httpRequest('GET', '/admin/trash', $stranger));
});
