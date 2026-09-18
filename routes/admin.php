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
use App\Controllers\Admin\UserFieldsController;
use App\Controllers\Admin\UsersController;
use App\Controllers\Admin\WebhooksController;
use Rsgrinko\Proton\Access\Permission;
use Rsgrinko\Proton\Http\Router;

return static function (Router $router): void {
    $router->group(['prefix' => '/admin', 'middleware' => ['csrf', 'auth']], function (Router $router): void {
        $router->get('', [DashboardController::class, 'index'])
            ->middleware('can:' . Permission::SYSTEM_VIEW)
            ->name('admin.dashboard');

        // Группа открыта по view ИЛИ manage — смотреть список и карточку может
        // и тот, кому не давали права их менять; сами правки требуют manage
        // отдельно на каждом мутирующем маршруте
        $router->group(['prefix' => '/users', 'middleware' => 'can:' . Permission::USERS_VIEW . '|' . Permission::USERS_MANAGE], function (Router $router): void {
            $router->get('', [UsersController::class, 'index'])->name('admin.users');
            $router->get('/export', [UsersController::class, 'export'])->name('admin.users.export');
            $router->get('/{id:\d+}', [UsersController::class, 'show'])->name('admin.users.show');

            $router->get('/new', [UsersController::class, 'create'])
                ->middleware('can:' . Permission::USERS_MANAGE)
                ->name('admin.users.create');
            $router->post('/new', [UsersController::class, 'store'])
                ->middleware('can:' . Permission::USERS_MANAGE);
            $router->post('/{id:\d+}', [UsersController::class, 'update'])
                ->middleware('can:' . Permission::USERS_MANAGE);
            $router->post('/{id:\d+}/delete', [UsersController::class, 'delete'])
                ->middleware('can:' . Permission::USERS_MANAGE)
                ->name('admin.users.delete');
            $router->post('/bulk', [UsersController::class, 'bulk'])
                ->middleware('can:' . Permission::USERS_MANAGE)
                ->name('admin.users.bulk');

            // Своя, более узкая проверка поверх группы — не всякий, кто ведёт
            // пользователей, должен уметь входить под ними
            $router->post('/{id:\d+}/impersonate', [UsersController::class, 'impersonate'])
                ->middleware('can:' . Permission::USERS_IMPERSONATE)
                ->name('admin.users.impersonate');
        });

        // Вне группы /users и без can: — во время подмены у виртуального
        // пользователя может не быть ни users.manage, ни users.impersonate,
        // а вернуться в свою сессию нужно всегда
        $router->post('/impersonate/stop', [UsersController::class, 'stopImpersonating'])
            ->name('admin.impersonate.stop');

        $router->group(['prefix' => '/user-fields', 'middleware' => 'can:' . Permission::USERS_MANAGE], function (Router $router): void {
            $router->get('', [UserFieldsController::class, 'index'])->name('admin.userFields');
            $router->get('/new', [UserFieldsController::class, 'create'])->name('admin.userFields.create');
            $router->post('/new', [UserFieldsController::class, 'store']);
            $router->get('/{id:\d+}', [UserFieldsController::class, 'show'])->name('admin.userFields.show');
            $router->post('/{id:\d+}', [UserFieldsController::class, 'update']);
            $router->post('/{id:\d+}/delete', [UserFieldsController::class, 'delete'])->name('admin.userFields.delete');
        });

        $router->group(['prefix' => '/roles', 'middleware' => 'can:' . Permission::ROLES_VIEW . '|' . Permission::ROLES_MANAGE], function (Router $router): void {
            $router->get('', [RolesController::class, 'index'])->name('admin.roles');
            $router->get('/{id:\d+}', [RolesController::class, 'show'])->name('admin.roles.show');

            $router->get('/new', [RolesController::class, 'create'])
                ->middleware('can:' . Permission::ROLES_MANAGE)
                ->name('admin.roles.create');
            $router->post('/new', [RolesController::class, 'store'])
                ->middleware('can:' . Permission::ROLES_MANAGE);
            $router->post('/{id:\d+}', [RolesController::class, 'update'])
                ->middleware('can:' . Permission::ROLES_MANAGE);
            $router->post('/{id:\d+}/delete', [RolesController::class, 'delete'])
                ->middleware('can:' . Permission::ROLES_MANAGE)
                ->name('admin.roles.delete');
        });

        $router->group(['prefix' => '/tokens', 'middleware' => 'can:' . Permission::TOKENS_VIEW . '|' . Permission::TOKENS_MANAGE], function (Router $router): void {
            $router->get('', [TokensController::class, 'index'])->name('admin.tokens');

            $router->post('', [TokensController::class, 'store'])
                ->middleware('can:' . Permission::TOKENS_MANAGE)
                ->name('admin.tokens.store');
            $router->post('/{id:\d+}/revoke', [TokensController::class, 'revoke'])
                ->middleware('can:' . Permission::TOKENS_MANAGE)
                ->name('admin.tokens.revoke');
            $router->post('/{id:\d+}/delete', [TokensController::class, 'delete'])
                ->middleware('can:' . Permission::TOKENS_MANAGE)
                ->name('admin.tokens.delete');
        });

        // Смотреть, менять и удалять — три разных права: кто-то видит вебхуки
        // и их доставки, кто-то ещё и правит их, а удалять подписку, на которую
        // завязана внешняя система, может третий, самый узкий круг
        $router->group(['prefix' => '/webhooks', 'middleware' => 'can:' . Permission::WEBHOOKS_VIEW . '|' . Permission::WEBHOOKS_MANAGE . '|' . Permission::WEBHOOKS_DELETE], function (Router $router): void {
            $router->get('', [WebhooksController::class, 'index'])->name('admin.webhooks');
            $router->get('/incoming', [WebhooksController::class, 'incoming'])->name('admin.webhooks.incoming');
            $router->get('/{id:\d+}', [WebhooksController::class, 'show'])->name('admin.webhooks.show');

            $router->post('/incoming/test', [WebhooksController::class, 'testIncoming'])
                ->middleware('can:' . Permission::WEBHOOKS_MANAGE)
                ->name('admin.webhooks.incoming.test');
            $router->get('/new', [WebhooksController::class, 'create'])
                ->middleware('can:' . Permission::WEBHOOKS_MANAGE)
                ->name('admin.webhooks.create');
            $router->post('/new', [WebhooksController::class, 'store'])
                ->middleware('can:' . Permission::WEBHOOKS_MANAGE);
            $router->post('/{id:\d+}', [WebhooksController::class, 'update'])
                ->middleware('can:' . Permission::WEBHOOKS_MANAGE);
            $router->post('/{id:\d+}/test', [WebhooksController::class, 'test'])
                ->middleware('can:' . Permission::WEBHOOKS_MANAGE)
                ->name('admin.webhooks.test');
            $router->post('/{id:\d+}/toggle', [WebhooksController::class, 'toggle'])
                ->middleware('can:' . Permission::WEBHOOKS_MANAGE)
                ->name('admin.webhooks.toggle');
            $router->post('/{id:\d+}/secret', [WebhooksController::class, 'rotate'])
                ->middleware('can:' . Permission::WEBHOOKS_MANAGE)
                ->name('admin.webhooks.rotate');
            $router->post('/{id:\d+}/delete', [WebhooksController::class, 'delete'])
                ->middleware('can:' . Permission::WEBHOOKS_DELETE)
                ->name('admin.webhooks.delete');
        });

        $router->group(['prefix' => '/backups', 'middleware' => 'can:' . Permission::BACKUPS_VIEW . '|' . Permission::BACKUPS_MANAGE], function (Router $router): void {
            $router->get('', [BackupsController::class, 'index'])->name('admin.backups');

            $router->post('', [BackupsController::class, 'store'])
                ->middleware('can:' . Permission::BACKUPS_MANAGE)
                ->name('admin.backups.store');
            $router->post('/download', [BackupsController::class, 'download'])
                ->middleware('can:' . Permission::BACKUPS_MANAGE)
                ->name('admin.backups.download');
            $router->post('/check', [BackupsController::class, 'check'])
                ->middleware('can:' . Permission::BACKUPS_MANAGE)
                ->name('admin.backups.check');
            $router->post('/ship', [BackupsController::class, 'ship'])
                ->middleware('can:' . Permission::BACKUPS_MANAGE)
                ->name('admin.backups.ship');
            $router->post('/delete', [BackupsController::class, 'delete'])
                ->middleware('can:' . Permission::BACKUPS_MANAGE)
                ->name('admin.backups.delete');
        });

        $router->group(['prefix' => '/settings', 'middleware' => 'can:' . Permission::SETTINGS_VIEW . '|' . Permission::SETTINGS_MANAGE], function (Router $router): void {
            $router->get('', [SettingsController::class, 'index'])->name('admin.settings');

            $router->post('', [SettingsController::class, 'update'])
                ->middleware('can:' . Permission::SETTINGS_MANAGE);
            $router->post('/reset', [SettingsController::class, 'reset'])
                ->middleware('can:' . Permission::SETTINGS_MANAGE)
                ->name('admin.settings.reset');
            $router->get('/export-env', [SettingsController::class, 'exportEnv'])
                ->middleware('can:' . Permission::SETTINGS_MANAGE)
                ->name('admin.settings.export-env');
        });

        $router->group(['prefix' => '/reports', 'middleware' => 'can:' . Permission::SYSTEM_VIEW], function (Router $router): void {
            $router->get('', [ReportsController::class, 'index'])->name('admin.reports');
            $router->get('/export', [ReportsController::class, 'export'])->name('admin.reports.export');
        });

        $router->group(['prefix' => '/security', 'middleware' => 'can:' . Permission::SECURITY_VIEW . '|' . Permission::SECURITY_MANAGE], function (Router $router): void {
            $router->get('', [SecurityController::class, 'index'])->name('admin.security');
            $router->get('/export', [SecurityController::class, 'export'])->name('admin.security.export');

            $router->post('/block', [SecurityController::class, 'block'])
                ->middleware('can:' . Permission::SECURITY_MANAGE)
                ->name('admin.security.block');
            $router->post('/unblock', [SecurityController::class, 'unblock'])
                ->middleware('can:' . Permission::SECURITY_MANAGE)
                ->name('admin.security.unblock');
        });

        $router->group(['prefix' => '/queue', 'middleware' => 'can:' . Permission::QUEUE_VIEW . '|' . Permission::QUEUE_MANAGE], function (Router $router): void {
            $router->get('', [QueueController::class, 'index'])->name('admin.queue');

            $router->post('/retry', [QueueController::class, 'retry'])
                ->middleware('can:' . Permission::QUEUE_MANAGE)
                ->name('admin.queue.retry');
            $router->post('/delete', [QueueController::class, 'delete'])
                ->middleware('can:' . Permission::QUEUE_MANAGE)
                ->name('admin.queue.delete');
            $router->post('/run', [QueueController::class, 'run'])
                ->middleware('can:' . Permission::QUEUE_MANAGE)
                ->name('admin.queue.run');
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
