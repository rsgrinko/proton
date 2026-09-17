<?php

declare(strict_types=1);

/**
 * Меню приложения.
 *
 * Каждый пункт: ключ (по нему подсвечивается активный раздел), подпись, имя
 * маршрута, право и группа. Пункт без права видят все вошедшие; пункт с правом
 * показывается только тому, у кого оно есть, — но доступ всё равно закрывает
 * прослойка can, разметка лишь не дразнит. Группа — только про разделитель в
 * боковом меню: пункты с одинаковым числом идут одним блоком, смена числа
 * между соседними пунктами рисует линию между блоками.
 */

use Rsgrinko\Proton\Access\Permission;

return [
    ['key' => 'home',      'label' => 'Главная',      'route' => 'home',            'permission' => '', 'group' => 1],
    ['key' => 'notes',     'label' => 'Заметки',      'route' => 'notes.index',     'permission' => 'notes.view', 'group' => 1],
    ['key' => 'dashboard', 'label' => 'Панель',       'route' => 'admin.dashboard', 'permission' => Permission::SYSTEM_VIEW, 'group' => 1],

    ['key' => 'users',       'label' => 'Пользователи', 'route' => 'admin.users',     'permission' => Permission::USERS_MANAGE, 'group' => 2],
    ['key' => 'user-fields', 'label' => 'Поля профиля', 'route' => 'admin.userFields', 'permission' => Permission::USERS_MANAGE, 'group' => 2],
    ['key' => 'roles',       'label' => 'Роли',         'route' => 'admin.roles',     'permission' => Permission::ROLES_MANAGE, 'group' => 2],
    ['key' => 'tokens',      'label' => 'Ключи API',    'route' => 'admin.tokens',    'permission' => Permission::USERS_MANAGE, 'group' => 2],

    ['key' => 'webhooks', 'label' => 'Вебхуки',      'route' => 'admin.webhooks', 'permission' => Permission::WEBHOOKS_MANAGE, 'group' => 3],
    ['key' => 'settings', 'label' => 'Настройки',    'route' => 'admin.settings', 'permission' => Permission::SETTINGS_MANAGE, 'group' => 3],
    ['key' => 'backups',  'label' => 'Копии',        'route' => 'admin.backups',  'permission' => Permission::SYSTEM_MANAGE, 'group' => 3],
    ['key' => 'queue',    'label' => 'Очередь',      'route' => 'admin.queue',    'permission' => Permission::SYSTEM_MANAGE, 'group' => 3],
    ['key' => 'security', 'label' => 'Безопасность', 'route' => 'admin.security', 'permission' => Permission::SYSTEM_MANAGE, 'group' => 3],
    ['key' => 'system',   'label' => 'Состояние',    'route' => 'admin.system',   'permission' => Permission::SYSTEM_VIEW, 'group' => 3],

    ['key' => 'reports', 'label' => 'Отчёты',   'route' => 'admin.reports', 'permission' => Permission::SYSTEM_VIEW, 'group' => 4],
    ['key' => 'audit',   'label' => 'Журнал',   'route' => 'admin.audit',   'permission' => Permission::AUDIT_VIEW, 'group' => 4],
    ['key' => 'logs',    'label' => 'Логи',     'route' => 'admin.logs',    'permission' => Permission::LOGS_VIEW, 'group' => 4],
    ['key' => 'trash',   'label' => 'Корзина',  'route' => 'admin.trash',   'permission' => '', 'group' => 4],
];
