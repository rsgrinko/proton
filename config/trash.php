<?php

declare(strict_types=1);

/**
 * Что показывать в «Корзине».
 *
 * Модель попадает сюда, только если у неё включено мягкое удаление
 * (`protected bool $softDelete = true`) — иначе возвращать нечего.
 * Право ограничивает раздел: чужие удалённые записи видит не каждый.
 */

use App\Models\Note;
use Rsgrinko\Proton\Access\Permission;
use Rsgrinko\Proton\Models\Role;
use Rsgrinko\Proton\Models\User;
use Rsgrinko\Proton\Models\Webhook;
use Rsgrinko\Proton\Support\Trash;

Trash::register(
    'users',
    User::class,
    'Пользователи',
    static fn (User $user): string => (string) $user->login . ' (' . (string) $user->email . ')',
    Permission::USERS_MANAGE,
    'user'
);

Trash::register(
    'roles',
    Role::class,
    'Роли',
    static fn (Role $role): string => (string) $role->name,
    Permission::ROLES_MANAGE,
    'role'
);

Trash::register(
    'webhooks',
    Webhook::class,
    'Вебхуки',
    static fn (Webhook $webhook): string => (string) $webhook->name . ' → ' . (string) $webhook->url,
    Permission::WEBHOOKS_MANAGE,
    'webhook'
);

Trash::register(
    'notes',
    Note::class,
    'Заметки',
    static fn (Note $note): string => (string) $note->title,
    'notes.manage',
    'note'
);
