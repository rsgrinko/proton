<?php

declare(strict_types=1);

/**
 * Автозагрузчик классов, PSR-4-подобный. Composer не используется.
 *
 * Отображение пространств имён на каталоги:
 *   Rsgrinko\Proton\  -> framework/   ядро
 *   App\              -> app/         код приложения
 *   App\Migrations\   -> migrations/  миграции (их подключает и сам Migrator)
 *
 * Своё пространство имён добавляется строкой в $map.
 */

if (!defined('APP_ROOT')) {
    define('APP_ROOT', str_replace('\\', '/', dirname(__DIR__)));
}

spl_autoload_register(static function (string $class): void {
    $map = [
        'Rsgrinko\\Proton\\' => APP_ROOT . '/framework/',
        'App\\Migrations\\'  => APP_ROOT . '/migrations/',
        'App\\'              => APP_ROOT . '/app/',
    ];

    foreach ($map as $prefix => $baseDir) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }

        $relative = substr($class, strlen($prefix));
        $file     = $baseDir . str_replace('\\', '/', $relative) . '.php';

        if (is_file($file)) {
            require $file;

            return;
        }
    }
});
