<?php

declare(strict_types=1);

/**
 * Приглашения: ссылка одноразовая, роль задаёт приглашающий и не шире своих
 * прав, регистрация по ссылке работает и при закрытой общей.
 */

use Rsgrinko\Proton\Database\Connection;
use Rsgrinko\Proton\Models\Invitation;
use Rsgrinko\Proton\Models\Role;
use Rsgrinko\Proton\Models\User;

function invitationTestEmail(): string
{
    return 'invite_' . bin2hex(random_bytes(4)) . '@example.com';
}

/**
 * Приглашения на адрес и всё, что по ним завелось, — убрать после прогона.
 */
function invitationTestCleanup(string $email): void
{
    afterTests(static function () use ($email): void {
        Invitation::query()->where('email', $email)->forceDelete();
        // LOWER — один из тестов нарочно заводит адрес в верхнем регистре
        User::query()->withTrashed()->whereRaw('LOWER(email) = ?', [$email])->forceDelete();
    });
}

function invitationTestRole(array $permissions): Role
{
    $role = Role::create([
        'name'        => 'Для приглашений ' . bin2hex(random_bytes(3)),
        'permissions' => $permissions,
    ]);

    afterTests(static fn () => $role->forceDelete());

    return $role;
}

test('приглашения: ссылка находит своё приглашение, повторная отправка гасит прежнюю', function (): void {
    $email = invitationTestEmail();
    invitationTestCleanup($email);

    $role  = invitationTestRole(['notes.view']);
    $first = Invitation::issue(strtoupper($email), $role->id());

    assertSame($email, (string) $first['invitation']->email, 'адрес хранится в нижнем регистре');
    assertSame($first['invitation']->id(), Invitation::match($first['token'])?->id());
    assertNull(Invitation::match('чужой-токен'));

    $second = Invitation::issue($email, $role->id());

    assertNull(Invitation::match($first['token']), 'старая ссылка после повторной отправки не работает');
    assertSame(Invitation::REVOKED, Invitation::find($first['invitation']->id())?->state());
    assertSame($second['invitation']->id(), Invitation::match($second['token'])?->id());
});

test('приглашения: просроченное по ссылке не находится', function (): void {
    $email = invitationTestEmail();
    invitationTestCleanup($email);

    $sent = Invitation::issue($email, invitationTestRole([])->id());
    $sent['invitation']->forceFill(['expires_at' => Connection::at(-60)])->save();

    assertSame(Invitation::EXPIRED, $sent['invitation']->state());
    assertNull(Invitation::match($sent['token']));
});

test('приглашения: по ссылке заводится пользователь с ролью приглашения даже при закрытой регистрации', function (): void {
    httpAdmin();

    $email = invitationTestEmail();
    invitationTestCleanup($email);

    $role = invitationTestRole(['notes.view']);
    $sent = Invitation::issue($email, $role->id(), httpAdmin()->id());
    $path = '/invite/' . $sent['token'];
    $body = [
        'login'                 => 'invited_' . bin2hex(random_bytes(3)),
        'password'              => 'секрет123',
        'password_confirmation' => 'секрет123',
    ];

    withConfig(['auth.registration' => false], function () use ($path, $body, $role, $email, $sent): void {
        assertStatus(200, httpRequest('GET', $path));
        assertRedirect('/', httpRequest('POST', $path, null, $body));

        /** @var User $user */
        $user = assertNotNull(User::findBy('login', $body['login']));

        assertSame($email, (string) $user->email);
        assertSame($role->id(), (int) $user->raw('role_id'), 'роль из приглашения, а не AUTH_REGISTRATION_ROLE');
        assertTrue($user->emailVerified(), 'адрес подтверждён самой ссылкой');

        $invitation = Invitation::find($sent['invitation']->id());

        assertSame(Invitation::ACCEPTED, $invitation?->state());
        assertSame($user->id(), (int) $invitation?->raw('user_id'));

        // Второй раз по той же ссылке — только уводит на вход
        assertRedirect('/login', httpRequest('GET', $path));
        assertRedirect('/login', httpRequest('POST', $path, null, ['login' => 'another_' . bin2hex(random_bytes(3))] + $body));
    });
});

test('приглашения: занятый за это время адрес — приглашение гаснет, второго пользователя нет', function (): void {
    httpAdmin();

    $email = invitationTestEmail();
    invitationTestCleanup($email);

    $sent = Invitation::issue($email, invitationTestRole([])->id());

    User::register('taken_' . bin2hex(random_bytes(3)), 'секрет123', ['email' => strtoupper($email)]);

    $login = 'late_' . bin2hex(random_bytes(3));

    assertRedirect('/login', httpRequest('POST', '/invite/' . $sent['token'], null, [
        'login'                 => $login,
        'password'              => 'секрет123',
        'password_confirmation' => 'секрет123',
    ]));

    assertNull(User::findBy('login', $login));
    assertSame(Invitation::REVOKED, Invitation::find($sent['invitation']->id())?->state());
});

test('приглашения: из панели — отправка, отзыв; роль шире своих прав не выдать', function (): void {
    $manager = User::register('inviter_' . bin2hex(random_bytes(3)), 'секрет123', [
        'role_id' => invitationTestRole(['users.manage', 'notes.view'])->id(),
    ]);

    afterTests(static fn () => $manager->forceDelete());

    $email = invitationTestEmail();
    invitationTestCleanup($email);

    $narrow = invitationTestRole(['notes.view']);
    $wide   = invitationTestRole(['users.manage', 'roles.manage']);

    assertStatus(200, httpRequest('GET', '/admin/invitations', $manager));

    assertStatus(403, httpRequest('POST', '/admin/invitations', $manager, ['email' => $email, 'role_id' => $wide->id()]));
    assertSame(0, Invitation::query()->where('email', $email)->count());

    assertRedirect('/admin/invitations', httpRequest('POST', '/admin/invitations', $manager, ['email' => $email, 'role_id' => $narrow->id()]));

    /** @var Invitation $invitation */
    $invitation = assertNotNull(Invitation::query()->where('email', $email)->first());

    assertSame($manager->id(), (int) $invitation->raw('invited_by'));
    assertTrue($invitation->isPending());

    httpRequest('POST', '/admin/invitations/' . $invitation->id() . '/revoke', $manager);

    assertSame(Invitation::REVOKED, Invitation::find($invitation->id())?->state());

    // Без users.manage раздел закрыт
    assertStatus(403, httpRequest('GET', '/admin/invitations', httpUser()));
});

test('приглашения: на адрес существующего пользователя не отправляется', function (): void {
    $email = invitationTestEmail();
    invitationTestCleanup($email);

    User::register('exists_' . bin2hex(random_bytes(3)), 'секрет123', ['email' => $email]);

    httpRequest('POST', '/admin/invitations', httpAdmin(), ['email' => strtoupper($email), 'role_id' => invitationTestRole([])->id()]);

    assertSame(0, Invitation::query()->where('email', $email)->count());
});
