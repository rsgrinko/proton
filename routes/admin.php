<?php

declare(strict_types=1);

/**
 * Маршруты панели управления.
 *
 * Вся группа закрыта прослойками auth и csrf, а каждый раздел — своим правом.
 * Право проверяется прослойкой, а не в контроллере: так забыть его можно
 * только вместе со всей группой.
 */

use App\Controllers\Admin\AuditController;
use App\Controllers\Admin\BackupsController;
use App\Controllers\Admin\DashboardController;
use App\Controllers\Admin\LogsController;
use App\Controllers\Admin\QueueController;
use App\Controllers\Admin\ReportsController;
use App\Controllers\Admin\RolesController;
use App\Controllers\Admin\SecurityController;
use App\Controllers\Admin\SettingsController;
use App\Controllers\Admin\SystemController;
use App\Controllers\Admin\TokensController;
use App\Controllers\Admin\TrashController;
use App\Controllers\Admin\UsersController;
use App\Controllers\Admin\WebhooksController;
use Rsgrinko\Proton\Access\Permission;
use Rsgrinko\Proton\Http\Router;

return static function (Router $router): void {
    $router->group(['prefix' => '/admin', 'middleware' => ['csrf', 'auth']], function (Router $router): void {
        $router->get('', [DashboardController::class, 'index'])
            ->middleware('can:' . Permission::SYSTEM_VIEW)
            ->name('admin.dashboard');

        $router->group(['prefix' => '/users', 'middleware' => 'can:' . Permission::USERS_MANAGE], function (Router $router): void {
            $router->get('', [UsersController::class, 'index'])->name('admin.users');
            $router->get('/export', [UsersController::class, 'export'])->name('admin.users.export');
            $router->get('/new', [UsersController::class, 'create'])->name('admin.users.create');
            $router->post('/new', [UsersController::class, 'store']);
            $router->get('/{id:\d+}', [UsersController::class, 'show'])->name('admin.users.show');
            $router->post('/{id:\d+}', [UsersController::class, 'update']);
            $router->post('/{id:\d+}/delete', [UsersController::class, 'delete'])->name('admin.users.delete');
            $router->post('/bulk', [UsersController::class, 'bulk'])->name('admin.users.bulk');
        });

        $router->group(['prefix' => '/roles', 'middleware' => 'can:' . Permission::ROLES_MANAGE], function (Router $router): void {
            $router->get('', [RolesController::class, 'index'])->name('admin.roles');
            $router->get('/new', [RolesController::class, 'create'])->name('admin.roles.create');
            $router->post('/new', [RolesController::class, 'store']);
            $router->get('/{id:\d+}', [RolesController::class, 'show'])->name('admin.roles.show');
            $router->post('/{id:\d+}', [RolesController::class, 'update']);
            $router->post('/{id:\d+}/delete', [RolesController::class, 'delete'])->name('admin.roles.delete');
        });

        $router->group(['prefix' => '/tokens', 'middleware' => 'can:' . Permission::USERS_MANAGE], function (Router $router): void {
            $router->get('', [TokensController::class, 'index'])->name('admin.tokens');
            $router->post('', [TokensController::class, 'store'])->name('admin.tokens.store');
            $router->post('/{id:\d+}/revoke', [TokensController::class, 'revoke'])->name('admin.tokens.revoke');
            $router->post('/{id:\d+}/delete', [TokensController::class, 'delete'])->name('admin.tokens.delete');
        });

        $router->group(['prefix' => '/webhooks', 'middleware' => 'can:' . Permission::WEBHOOKS_MANAGE], function (Router $router): void {
            $router->get('', [WebhooksController::class, 'index'])->name('admin.webhooks');
            $router->get('/incoming', [WebhooksController::class, 'incoming'])->name('admin.webhooks.incoming');
            $router->post('/incoming/test', [WebhooksController::class, 'testIncoming'])->name('admin.webhooks.incoming.test');
            $router->get('/new', [WebhooksController::class, 'create'])->name('admin.webhooks.create');
            $router->post('/new', [WebhooksController::class, 'store']);
            $router->get('/{id:\d+}', [WebhooksController::class, 'show'])->name('admin.webhooks.show');
            $router->post('/{id:\d+}', [WebhooksController::class, 'update']);
            $router->post('/{id:\d+}/test', [WebhooksController::class, 'test'])->name('admin.webhooks.test');
            $router->post('/{id:\d+}/toggle', [WebhooksController::class, 'toggle'])->name('admin.webhooks.toggle');
            $router->post('/{id:\d+}/secret', [WebhooksController::class, 'rotate'])->name('admin.webhooks.rotate');
            $router->post('/{id:\d+}/delete', [WebhooksController::class, 'delete'])->name('admin.webhooks.delete');
        });

        // Копии базы — под правом обслуживания: тот же человек перезапускает
        // воркер и чистит очередь
        $router->group(['prefix' => '/backups', 'middleware' => 'can:' . Permission::SYSTEM_MANAGE], function (Router $router): void {
            $router->get('', [BackupsController::class, 'index'])->name('admin.backups');
            $router->post('', [BackupsController::class, 'store'])->name('admin.backups.store');
            $router->post('/download', [BackupsController::class, 'download'])->name('admin.backups.download');
            $router->post('/check', [BackupsController::class, 'check'])->name('admin.backups.check');
            $router->post('/delete', [BackupsController::class, 'delete'])->name('admin.backups.delete');
        });

        $router->group(['prefix' => '/settings', 'middleware' => 'can:' . Permission::SETTINGS_MANAGE], function (Router $router): void {
            $router->get('', [SettingsController::class, 'index'])->name('admin.settings');
            $router->post('', [SettingsController::class, 'update']);
            $router->post('/reset', [SettingsController::class, 'reset'])->name('admin.settings.reset');
        });

        $router->group(['prefix' => '/reports', 'middleware' => 'can:' . Permission::SYSTEM_VIEW], function (Router $router): void {
            $router->get('', [ReportsController::class, 'index'])->name('admin.reports');
            $router->get('/export', [ReportsController::class, 'export'])->name('admin.reports.export');
        });

        $router->group(['prefix' => '/security', 'middleware' => 'can:' . Permission::SYSTEM_MANAGE], function (Router $router): void {
            $router->get('', [SecurityController::class, 'index'])->name('admin.security');
            $router->get('/export', [SecurityController::class, 'export'])->name('admin.security.export');
            $router->post('/block', [SecurityController::class, 'block'])->name('admin.security.block');
            $router->post('/unblock', [SecurityController::class, 'unblock'])->name('admin.security.unblock');
        });

        $router->group(['prefix' => '/queue', 'middleware' => 'can:' . Permission::SYSTEM_MANAGE], function (Router $router): void {
            $router->get('', [QueueController::class, 'index'])->name('admin.queue');
            $router->post('/retry', [QueueController::class, 'retry'])->name('admin.queue.retry');
            $router->post('/delete', [QueueController::class, 'delete'])->name('admin.queue.delete');
            $router->post('/run', [QueueController::class, 'run'])->name('admin.queue.run');
        });

        // Корзина: разделы объявляет реестр, право проверяется внутри — у каждого
        // раздела оно своё
        $router->group(['prefix' => '/trash'], function (Router $router): void {
            $router->get('', [TrashController::class, 'index'])->name('admin.trash');
            $router->post('/restore', [TrashController::class, 'restore'])->name('admin.trash.restore');
            $router->post('/destroy', [TrashController::class, 'destroy'])->name('admin.trash.destroy');
            $router->post('/bulk', [TrashController::class, 'bulk'])->name('admin.trash.bulk');
            $router->post('/clear', [TrashController::class, 'clear'])->name('admin.trash.clear');
        });

        $router->get('/audit', [AuditController::class, 'index'])
            ->middleware('can:' . Permission::AUDIT_VIEW)
            ->name('admin.audit');

        $router->get('/audit/export', [AuditController::class, 'export'])
            ->middleware('can:' . Permission::AUDIT_VIEW)
            ->name('admin.audit.export');

        $router->get('/logs', [LogsController::class, 'index'])
            ->middleware('can:' . Permission::LOGS_VIEW)
            ->name('admin.logs');

        $router->get('/system', [SystemController::class, 'index'])
            ->middleware('can:' . Permission::SYSTEM_VIEW)
            ->name('admin.system');

        // Кнопки обслуживания — отдельным правом: смотреть состояние можно
        // и без возможности что-то перезапускать
        $router->post('/system/{action}', [SystemController::class, 'action'])
            ->middleware('can:' . Permission::SYSTEM_MANAGE)
            ->name('admin.system.action');
    });
};
