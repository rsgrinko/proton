<?php

declare(strict_types=1);

/**
 * Маршруты API.
 *
 * Авторизация — ключом в заголовке: Authorization: Bearer ptn_xxx_yyy.
 * Токен форм здесь не нужен (кук нет), зато стоит ограничение частоты.
 */

use App\Controllers\Api\HooksController;
use App\Controllers\Api\NotesController;
use App\Controllers\Api\SystemController;
use Rsgrinko\Proton\Http\Router;

return static function (Router $router): void {
    // Отдельно от /api/v1: так его ждёт Prometheus, и путь короче в конфиге
    // сборщика. Ключа нет — вместо него список адресов METRICS_ALLOW
    $router->get('/metrics', [SystemController::class, 'prometheus'])
        ->middleware('throttle:60,60')
        ->name('metrics.prometheus');

    $router->group(['prefix' => '/api/v1'], function (Router $router): void {
        // Входящие вебхуки: ключа у чужой системы нет, вместо него подпись.
        // Частоту всё равно ограничиваем — источник может залипнуть в цикле
        $router->post('/hooks/{source}', [HooksController::class, 'receive'])
            ->middleware('throttle:600,60')
            ->name('api.hooks');

        // Те же вебхуки через GET: так шлют системы, которые не умеют POST.
        // Тела у такой посылки нет — всё в параметрах адреса
        $router->get('/hooks/{source}', [HooksController::class, 'receive'])
            ->middleware('throttle:600,60')
            ->name('api.hooks.get');

        // Живость сервиса дёргает мониторинг по расписанию — без ключа
        $router->get('/health', [SystemController::class, 'health'])->name('api.health');

        $router->group(['middleware' => ['api', 'throttle:120,60']], function (Router $router): void {
            $router->get('/me', [SystemController::class, 'me'])->name('api.me');
            $router->get('/metrics', [SystemController::class, 'metrics'])->name('api.metrics');

            $router->get('/notes', [NotesController::class, 'index'])->name('api.notes.index');
            $router->post('/notes', [NotesController::class, 'store'])->name('api.notes.store');
            $router->get('/notes/{id:\d+}', [NotesController::class, 'show'])->name('api.notes.show');
            $router->patch('/notes/{id:\d+}', [NotesController::class, 'update'])->name('api.notes.update');
            $router->delete('/notes/{id:\d+}', [NotesController::class, 'delete'])->name('api.notes.delete');
        });
    });
};
