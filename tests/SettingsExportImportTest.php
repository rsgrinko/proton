<?php

declare(strict_types=1);

/**
 * Перенос настроек и ролей между окружениями: settings:export / settings:import.
 */

use Rsgrinko\Proton\Console\Commands\SettingsExportCommand;
use Rsgrinko\Proton\Console\Commands\SettingsImportCommand;
use Rsgrinko\Proton\Models\Role;
use Rsgrinko\Proton\Support\Settings;

/**
 * Гоняет команду и возвращает код завершения вместе со всем, что она напечатала.
 *
 * @return array{status: int, lines: array<int, string>}
 */
function runSettingsCommand(object $command, array $args, array $options): array
{
    $lines = [];

    $status = $command->withInput($args, $options, static function (string $line) use (&$lines): void {
        $lines[] = trim($line);
    })->run();

    return ['status' => $status, 'lines' => $lines];
}

test('settings:export: секрет пропускается, встроенная роль — тоже', function (): void {
    withOwnDatabase(static function (): void {
        Settings::reset();
        Settings::put('ui.per_page', '77');

        $path = APP_ROOT . '/var/export/test-export.json';

        $result = runSettingsCommand(new SettingsExportCommand(), [], ['out' => 'var/export/test-export.json']);

        try {
            assertSame(0, $result['status']);
            assertTrue(is_file($path));

            $data = json_decode((string) file_get_contents($path), true);

            assertSame(77, $data['settings']['ui.per_page'] ?? null);

            // Встроенная роль администратора в выгрузку не попадает
            $names = array_column($data['roles'], 'name');

            assertFalse(in_array('Администратор', $names, true));
        } finally {
            @unlink($path);
            Settings::forget('ui.per_page');
            Settings::reset();
        }
    });
});

test('settings:import: без подтверждения ничего не трогает', function (): void {
    withOwnDatabase(static function (): void {
        Settings::reset();

        $path = APP_ROOT . '/var/export/test-import-noforce.json';

        file_put_contents($path, json_encode([
            'settings' => ['ui.per_page' => 99],
            'roles'    => [],
        ]));

        try {
            $result = runSettingsCommand(new SettingsImportCommand(), [$path], []);

            assertSame(1, $result['status'], 'без --force и без TTY подтверждения не будет');
            assertFalse(Settings::overridden('ui.per_page'), 'настройка не применилась');
        } finally {
            @unlink($path);
            Settings::reset();
        }
    });
});

test('settings:import: применяет настройки, заводит и правит роли, не трогает чужого', function (): void {
    withOwnDatabase(static function (): void {
        Settings::reset();

        /** @var Role $existing */
        $existing = Role::create([
            'name'        => 'Уже была',
            'description' => 'старое описание',
            'permissions' => ['notes.view'],
        ]);

        $path = APP_ROOT . '/var/export/test-import.json';

        file_put_contents($path, json_encode([
            'settings' => ['ui.per_page' => 55, 'нет.такой' => 'x'],
            'roles'    => [
                ['name' => 'Уже была', 'description' => 'новое описание', 'permissions' => ['notes.view', 'notes.manage']],
                ['name' => 'Новая из импорта', 'description' => 'создана импортом', 'permissions' => ['notes.view']],
            ],
        ], JSON_UNESCAPED_UNICODE));

        try {
            $result = runSettingsCommand(new SettingsImportCommand(), [$path], ['force' => '1']);

            assertSame(0, $result['status']);
            assertSame(55, (int) Settings::value('ui.per_page'));

            $updated = Role::find($existing->id());

            assertSame('новое описание', (string) $updated->description);
            assertSame(['notes.view', 'notes.manage'], $updated->permissions());

            /** @var Role $created */
            $created = assertNotNull(Role::query()->where('name', 'Новая из импорта')->first());

            assertSame('создана импортом', (string) $created->description);

            $unknown = array_filter($result['lines'], static fn (string $line): bool => str_contains($line, 'нет.такой'));

            assertTrue($unknown !== [], 'неизвестная настройка отмечена в выводе');

            $created->forceDelete();
        } finally {
            @unlink($path);
            $existing->forceDelete();
            Settings::forget('ui.per_page');
            Settings::reset();
        }
    });
});

test('settings:import: встроенную роль администратора не трогает', function (): void {
    withOwnDatabase(static function (): void {
        Settings::reset();

        /** @var Role $admin */
        $admin = Role::admin();
        $before = $admin->permissions();

        $path = APP_ROOT . '/var/export/test-import-admin.json';

        file_put_contents($path, json_encode([
            'settings' => [],
            'roles'    => [
                ['name' => (string) $admin->name, 'description' => 'подменили бы', 'permissions' => ['notes.view']],
            ],
        ], JSON_UNESCAPED_UNICODE));

        try {
            $result = runSettingsCommand(new SettingsImportCommand(), [$path], ['force' => '1']);

            assertSame(0, $result['status']);

            $stillAdmin = Role::find($admin->id());

            assertSame($before, $stillAdmin->permissions(), 'права администратора всегда из кода');
        } finally {
            @unlink($path);
            Settings::reset();
        }
    });
});
