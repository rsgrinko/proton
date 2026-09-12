<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Console\Commands;

use Rsgrinko\Proton\Access\Permission;
use Rsgrinko\Proton\Console\Command;
use Rsgrinko\Proton\Models\Role;

/**
 * роли и их права.
 */
final class RoleListCommand extends Command
{
    public function name(): string
    {
        return 'role:list';
    }

    public function description(): string
    {
        return 'роли и их права (правятся в панели)';
    }

    public function usage(): string
    {
        return 'role:list [название]';
    }

    public function run(): int
    {
        $name = trim($this->arg(0));

        if ($name !== '') {
            $role = Role::findBy('name', $name);

            if ($role === null) {
                $this->fail('Роль не найдена: ' . $name);

                return 1;
            }

            $this->line('Роль «' . $role->name . '»' . ($role->isSystem() ? ' (встроенная)' : ''));
            $this->line('');

            foreach ($role->permissions() as $code) {
                $this->line('  ' . $this->pad($code, 24) . Permission::label($code));
            }

            return 0;
        }

        $rows = [];

        foreach (Role::all() as $role) {
            $rows[] = [
                (string) $role->id(),
                (string) $role->name,
                $role->isSystem() ? 'встроенная' : 'обычная',
                (string) count($role->permissions()),
                (string) $role->usersCount(),
            ];
        }

        if ($rows === []) {
            $this->line('Ролей нет — накатите миграции');

            return 0;
        }

        $this->table(['id', 'Название', 'Тип', 'Прав', 'Пользователей'], $rows);

        return 0;
    }
}
