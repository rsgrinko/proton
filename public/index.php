<?php

declare(strict_types=1);

/**
 * Единая точка входа. Всё, что не является файлом на диске, nginx уводит сюда
 * (см. docs/DEPLOY.md), а маршруты разбираются в routes/.
 *
 * Здесь нарочно почти ничего нет: вся работа — в ядре.
 */

require dirname(__DIR__) . '/bootstrap.php';

use Rsgrinko\Proton\Http\Kernel;
use Rsgrinko\Proton\Http\Request;

(new Kernel())->handle(Request::fromGlobals())->send();
