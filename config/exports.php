<?php

declare(strict_types=1);

/**
 * Выгрузки, которые умеет готовить очередь.
 *
 * Синхронная кнопка «Выгрузить CSV» отдаёт файл сразу, пока строк немного.
 * Всё, что больше EXPORT_MAX_ROWS, уходит сюда: задача собирает запрос заново
 * по имени и параметрам отбора, поэтому запрос описывается здесь, а не
 * в контроллере.
 *
 * Запрос получает параметры адреса и того, кто заказал выгрузку: область
 * видимости должна остаться в силе и в фоне.
 */

use App\Models\Note;
use Rsgrinko\Proton\Access\Permission;
use Rsgrinko\Proton\Access\Viewer;
use Rsgrinko\Proton\Database\Query\Builder;
use Rsgrinko\Proton\Models\AuditEntry;
use Rsgrinko\Proton\Models\User;
use Rsgrinko\Proton\Support\Exports;
use Rsgrinko\Proton\Support\Filter;
use Rsgrinko\Proton\Support\Filters;
use Rsgrinko\Proton\Http\Request;

Exports::register(
    'users',
    'Пользователи',
    static function (array $params, Viewer $viewer): Builder {
        $filters = Filters::make(Request::create('GET', '/admin/users', [], $params), [
            Filter::search('q', 'Поиск', ['login', 'email', 'name']),
            Filter::select('role_id', 'Роль', []),
            Filter::flag('active', 'Активен'),
            Filter::dates('created', 'Заведён', 'created_at'),
        ]);

        return $filters->apply(User::query());
    },
    [
        'id'            => 'ID',
        'login'         => 'Логин',
        'name'          => 'Имя',
        'email'         => 'Почта',
        'active'        => ['Активен', static fn (User $user): string => $user->isActive() ? 'да' : 'нет'],
        'created_at'    => 'Заведён',
        'last_login_at' => 'Последний вход',
    ],
    Permission::USERS_MANAGE
);

Exports::register(
    'audit',
    'Журнал действий',
    static function (array $params, Viewer $viewer): Builder {
        $filters = Filters::make(Request::create('GET', '/admin/audit', [], $params), [
            Filter::select('action', 'Действие', AuditEntry::LABELS),
            Filter::text('entity', 'Раздел'),
            Filter::search('q', 'Поиск', ['description', 'user_login']),
            Filter::dates('when', 'Когда', 'created_at'),
        ]);

        return $filters->apply(AuditEntry::query());
    },
    [
        'created_at'  => 'Когда',
        'user_login'  => 'Кто',
        'action'      => ['Действие', static fn (AuditEntry $entry): string => AuditEntry::label((string) $entry->raw('action'))],
        'entity'      => 'Раздел',
        'description' => 'Описание',
        'ip'          => 'Адрес',
    ],
    Permission::AUDIT_VIEW
);

Exports::register(
    'notes',
    'Заметки',
    static function (array $params, Viewer $viewer): Builder {
        $filters = Filters::make(Request::create('GET', '/notes', [], $params), [
            Filter::search('q', 'Поиск', ['title', 'body']),
            Filter::flag('pinned', 'Закреплена'),
            Filter::dates('created', 'Создана', 'created_at'),
        ]);

        // Область видимости заказчика: в фоне она так же обязательна
        return $filters->apply($viewer->scope()->apply(Note::query()));
    },
    [
        'id'         => 'ID',
        'title'      => 'Название',
        'body'       => 'Текст',
        'pinned'     => ['Закреплена', static fn (Note $note): string => $note->pinned ? 'да' : 'нет'],
        'created_at' => 'Создана',
    ],
    'notes.view'
);
