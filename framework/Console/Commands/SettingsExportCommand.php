<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Console\Commands;

use Rsgrinko\Proton\Console\Command;
use Rsgrinko\Proton\Models\Role;
use Rsgrinko\Proton\Support\Config;
use Rsgrinko\Proton\Support\Settings;

/**
 * Выгружает настройки, правленные из панели, и свои роли — переносить между
 * окружениями (staging → прод), а не переписывать .env и права руками.
 *
 * Секреты (тип Settings::SECRET, например ключ почтового сервиса) в файл не
 * попадают: они зашифрованы мастер-ключом этого окружения (APP_KEY), на другом
 * не расшифруются, а хранить их в файле открытым текстом — тоже не дело.
 * Список пропущенных ключей команда печатает — их правят в целевом .env руками.
 *
 * Встроенная роль администратора не выгружается: её права всегда берутся
 * из кода, записывать их некуда и незачем.
 */
final class SettingsExportCommand extends Command
{
    public function name(): string
    {
        return 'settings:export';
    }

    public function description(): string
    {
        return 'выгрузить настройки и роли в JSON — переносить между окружениями';
    }

    public function usage(): string
    {
        return 'settings:export [--out=var/export/settings.json]';
    }

    public function run(): int
    {
        $settings = [];
        $skipped  = [];

        foreach (Settings::groups() as $items) {
            foreach (array_keys($items) as $key) {
                if (!Settings::overridden($key)) {
                    continue;
                }

                if (($items[$key]['type'] ?? '') === Settings::SECRET) {
                    $skipped[] = $key;

                    continue;
                }

                $settings[$key] = Settings::value($key);
            }
        }

        $roles = [];

        /** @var Role $role */
        foreach (Role::query()->where('is_system', 0)->orderBy('id')->get() as $role) {
            $roles[] = [
                'name'        => (string) $role->name,
                'description' => (string) $role->description,
                'permissions' => $role->permissions,
            ];
        }

        $payload = [
            'app'         => (string) Config::get('app.name', 'Proton'),
            'exported_at' => date('Y-m-d H:i:s'),
            'settings'    => $settings,
            'roles'       => $roles,
        ];

        if ($skipped !== []) {
            $payload['settings_skipped_secret'] = $skipped;
        }

        $path = (string) $this->option('out', 'var/export/settings-' . date('Ymd-His') . '.json');
        $full = str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path) === 1
            ? $path
            : APP_ROOT . '/' . $path;

        $dir = dirname($full);

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            $this->fail('Не удалось создать каталог ' . $dir);

            return 1;
        }

        $json = (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (@file_put_contents($full, $json) === false) {
            $this->fail('Не удалось записать файл ' . $full);

            return 1;
        }

        $this->ok('Настроек: ' . count($settings) . ', ролей: ' . count($roles));

        if ($skipped !== []) {
            $this->line('  Секреты пропущены, перенесите руками: ' . implode(', ', $skipped));
        }

        $this->line('Файл: ' . str_replace(APP_ROOT . '/', '', str_replace('\\', '/', $full)));

        return 0;
    }
}
