<?php

declare(strict_types=1);

/**
 * Меню приложения.
 *
 * Каждый пункт: ключ (по нему подсвечивается активный раздел), подпись, имя
 * маршрута и право. Пункт без права видят все вошедшие; пункт с правом
 * показывается только тому, у кого оно есть, — но доступ всё равно закрывает
 * прослойка can, разметка лишь не дразнит.
 */

use Rsgrinko\Proton\Access\Permission;

return [
    ['key' => 'home',      'label' => 'Главная',      'route' => 'home',            'permission' => ''],
    ['key' => 'notes',     'label' => 'Заметки',      'route' => 'notes.index',     'permission' => 'notes.view'],
    ['key' => 'dashboard', 'label' => 'Панель',       'route' => 'admin.dashboard', 'permission' => Permission::SYSTEM_VIEW],
    ['key' => 'users',     'label' => 'Пользователи', 'route' => 'admin.users',     'permission' => Permission::USERS_MANAGE],
    ['key' => 'roles',     'label' => 'Роли',         'route' => 'admin.roles',     'permission' => Permission::ROLES_MANAGE],
    ['key' => 'tokens',    'label' => 'Ключи API',    'route' => 'admin.tokens',    'permission' => Permission::USERS_MANAGE],
    ['key' => 'webhooks',  'label' => 'Вебхуки',      'route' => 'admin.webhooks',  'permission' => Permission::WEBHOOKS_MANAGE],
    ['key' => 'settings',  'label' => 'Настройки',    'route' => 'admin.settings',  'permission' => Permission::SETTINGS_MANAGE],
    ['key' => 'backups',   'label' => 'Копии',        'route' => 'admin.backups',   'permission' => Permission::SYSTEM_MANAGE],
    ['key' => 'audit',     'label' => 'Журнал',       'route' => 'admin.audit',     'permission' => Permission::AUDIT_VIEW],
    ['key' => 'logs',      'label' => 'Логи',         'route' => 'admin.logs',      'permission' => Permission::LOGS_VIEW],
    ['key' => 'system',    'label' => 'Состояние',    'route' => 'admin.system',    'permission' => Permission::SYSTEM_VIEW],
];
