<?php

declare(strict_types=1);

/**
 * Роутер встроенного сервера PHP (php -S, команда `proton serve`).
 *
 * Существующие файлы отдаём как есть, остальное уводим в index.php — так
 * встроенный сервер ведёт себя как nginx с try_files. На бою этот файл не нужен.
 */

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$file = __DIR__ . $path;

if ($path !== '/' && is_file($file)) {
    return false;
}

require __DIR__ . '/index.php';
