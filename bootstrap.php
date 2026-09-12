<?php

declare(strict_types=1);

/**
 * Общий старт для всех точек входа: сайта, панели, API, консоли и тестов.
 * Подключает автозагрузчик, читает конфигурацию, готовит рабочие каталоги.
 */

if (PHP_VERSION_ID < 80100) {
    fwrite(STDERR, 'Нужен PHP 8.1 или новее, установлен ' . PHP_VERSION . PHP_EOL);

    exit(1);
}

define('APP_ROOT', str_replace('\\', '/', __DIR__));

require APP_ROOT . '/framework/Autoload.php';

use Rsgrinko\Proton\Support\Config;

Config::load();

date_default_timezone_set((string) Config::get('app.timezone', 'UTC'));
mb_internal_encoding('UTF-8');

// Рабочие каталоги создаём сразу, чтобы потом не ловить ошибки записи
foreach (['paths.log', 'paths.tmp', 'paths.cache', 'paths.storage'] as $key) {
    $dir = Config::get($key);

    if (is_string($dir) && $dir !== '' && !is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
}

if ((bool) Config::get('app.debug', false)) {
    error_reporting(E_ALL);
    ini_set('display_errors', PHP_SAPI === 'cli' ? '1' : '0');
} else {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
    ini_set('display_errors', '0');
}
