<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Console\Commands;

use Rsgrinko\Proton\Access\Permission;
use Rsgrinko\Proton\Console\Command;
use Rsgrinko\Proton\Models\Role;
use Rsgrinko\Proton\Support\Audit;
use Rsgrinko\Proton\Support\Settings;

/**
 * Применяет файл, выгруженный settings:export, на этой базе.
 *
 * Настройка, которой здесь не знает реестр (config/settings.php устарел или
 * это код другой версии), пропускается, а не падает всей командой. Роль ищется
 * по имени: совпало — правится описание и права, не совпало — заводится новая.
 * Встроенную роль администратора команда не трогает — её права из кода.
 *
 * Ничего не удаляется: лишних в файле ролей на базе не тронет, а настройка,
 * которой в файле нет, останется как была.
 */
final class SettingsImportCommand extends Command
{
    public function name(): string
    {
        return 'settings:import';
    }

    public function description(): string
    {
        return 'применить настройки и роли из файла settings:export';
    }

    public function usage(): string
    {
        return 'settings:import <файл> [--force]';
    }

    public function run(): int
    {
        $path = trim($this->arg(0));

        if ($path === '') {
            $this->fail('Укажите файл: php bin/proton settings:import var/export/settings.json');

            return 1;
        }

        $full = str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path) === 1
            ? $path
            : APP_ROOT . '/' . $path;

        if (!is_file($full)) {
            $this->fail('Файл не найден: ' . $path);

            return 1;
        }

        $data = json_decode((string) file_get_contents($full), true);

        if (!is_array($data)) {
            $this->fail('Не разобрал JSON: ' . $path);

            return 1;
        }

        /** @var array<string, mixed> $settings */
        $settings = (array) ($data['settings'] ?? []);
        /** @var array<int, array<string, mixed>> $roles */
        $roles = (array) ($data['roles'] ?? []);

        $this->line('В файле: настроек — ' . count($settings) . ', ролей — ' . count($roles));

        if (!$this->confirm('Применить на этой базе?')) {
            $this->line('Отменено');

            return 1;
        }

        $appliedSettings = 0;
        $unknownSettings = [];

        foreach ($settings as $key => $value) {
            $key = (string) $key;

            if (!Settings::known($key)) {
                $unknownSettings[] = $key;

                continue;
            }

            Settings::put($key, $value);
            $appliedSettings++;
        }

        $createdRoles = 0;
        $updatedRoles = 0;
        $skippedRoles = [];

        foreach ($roles as $row) {
            $name = trim((string) ($row['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $description = (string) ($row['description'] ?? '');
            $permissions = Permission::filter((array) ($row['permissions'] ?? []));

            /** @var Role|null $role */
            $role = Role::query()->where('name', $name)->first();

            if ($role === null) {
                Role::create([
                    'name'        => $name,
                    'description' => $description,
                    'permissions' => $permissions,
                    'is_system'   => 0,
                ]);

                $createdRoles++;

                continue;
            }

            if ($role->isSystem()) {
                $skippedRoles[] = $name;

                continue;
            }

            $role->forceFill(['description' => $description, 'permissions' => $permissions])->save();

            $updatedRoles++;
        }

        Audit::action(
            'settings',
            0,
            'импорт из файла: настроек — ' . $appliedSettings . ', ролей заведено — ' . $createdRoles . ', обновлено — ' . $updatedRoles
        );

        $this->ok('Настроек применено: ' . $appliedSettings . ', ролей заведено: ' . $createdRoles . ', обновлено: ' . $updatedRoles);

        if ($unknownSettings !== []) {
            $this->line('  Неизвестные настройки пропущены: ' . implode(', ', $unknownSettings));
        }

        if ($skippedRoles !== []) {
            $this->line('  Встроенную роль не трогаем: ' . implode(', ', $skippedRoles));
        }

        return 0;
    }
}
