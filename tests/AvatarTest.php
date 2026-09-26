<?php

declare(strict_types=1);

/**
 * Аватары в списках: User::preloadAvatars() отдаёт то же, что поход в базу
 * по одному, только одним запросом на всю страницу.
 */

use Rsgrinko\Proton\Files\Attachment;
use Rsgrinko\Proton\Models\User;

/**
 * Запись вложения без файла — preloadAvatars() смотрит только в таблицу.
 */
function avatarTestAttachment(User $user, string $path): Attachment
{
    $attachment = new Attachment();

    $attachment->forceFill([
        'entity'    => 'avatar',
        'entity_id' => (string) $user->id(),
        'path'      => $path,
        'name'      => 'photo.png',
        'mime'      => 'image/png',
        'size'      => 1,
    ])->save();

    afterTests(static fn () => $attachment->forceDelete());

    return $attachment;
}

function avatarTestUser(): User
{
    $user = User::register('avatar_' . bin2hex(random_bytes(3)), 'секрет123');

    afterTests(static fn () => $user->forceDelete());

    return $user;
}

test('аватары: предзагрузка берёт свежее фото каждого и пустоту у тех, у кого его нет', function (): void {
    $withTwo = avatarTestUser();
    $without = avatarTestUser();

    avatarTestAttachment($withTwo, 'avatar/old.png');
    $fresh = avatarTestAttachment($withTwo, 'avatar/new.png');

    // Свежие объекты: у тех, что выше, флага предзагрузки нет и не будет
    $users = [User::find($withTwo->id()), User::find($without->id())];

    User::preloadAvatars($users);

    assertSame($fresh->id(), $users[0]->avatarAttachment()?->id(), 'действующее — последнее загруженное');
    assertNull($users[1]->avatarAttachment(), 'без фото — null, а не чужое');

    // Без предзагрузки — тот же ответ из базы
    assertSame($fresh->id(), $withTwo->avatarAttachment()?->id());
});

test('аватары: без предзагрузки новое фото видно тому же объекту сразу', function (): void {
    $user = avatarTestUser();

    assertNull($user->avatarAttachment());

    $attachment = avatarTestAttachment($user, 'avatar/late.png');

    assertSame($attachment->id(), $user->avatarAttachment()?->id(), 'объект не держит старый ответ');
});
