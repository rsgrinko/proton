<?php

declare(strict_types=1);

/**
 * Маршруты сайта.
 *
 * Адреса нигде не пишутся строкой: у каждого маршрута есть имя, и ссылки
 * собираются через View::route('notes.show', ['id' => 5]). Поменялся путь —
 * правится одна строка здесь.
 *
 * Доступ задаётся прослойками у группы: guest — только не вошедшим,
 * auth — только вошедшим, can:право — по праву. Прослойка csrf стоит на всей
 * группе: форма без токена получит 403.
 */

use App\Controllers\Web\AuthController;
use App\Controllers\Web\ExportsController;
use App\Controllers\Web\HomeController;
use App\Controllers\Web\InstallController;
use App\Controllers\Web\NotesController;
use App\Controllers\Web\NotificationsController;
use App\Controllers\Web\PasswordController;
use App\Controllers\Web\ProfileController;
use App\Controllers\Web\RegisterController;
use App\Http\Middleware\SlowRequestMiddleware;
use Rsgrinko\Proton\Http\Router;

return static function (Router $router): void {
    // Своя прослойка регистрируется здесь же, рядом с маршрутами, которые её
    // используют, — ядро (Kernel) трогать не нужно. Полный разбор —
    // docs/ROUTING.md, раздел «Прослойки»
    $router->middleware('slowlog', new SlowRequestMiddleware());

    $router->group(['middleware' => ['csrf']], function (Router $router): void {
        // Веб-установщик: открыт, только пока приложение не установлено.
        // То же самое умеет php bin/proton install
        $router->get('/install', [InstallController::class, 'show'])->middleware('install')->name('install');
        $router->post('/install', [InstallController::class, 'store'])->middleware('install');


        // Вход и регистрация — только для не вошедших
        $router->group(['middleware' => ['guest']], function (Router $router): void {
            $router->get('/login', [AuthController::class, 'showLogin'])->name('login');
            $router->post('/login', [AuthController::class, 'login']);

            $router->get('/register', [RegisterController::class, 'show'])->name('register');
            $router->post('/register', [RegisterController::class, 'store']);

            $router->get('/password/forgot', [PasswordController::class, 'showForgot'])->name('password.forgot');
            $router->post('/password/forgot', [PasswordController::class, 'forgot']);

            $router->get('/password/reset/{token}', [PasswordController::class, 'showReset'])->name('password.reset');
            $router->post('/password/reset/{token}', [PasswordController::class, 'reset']);
        });

        // Файл по подписанной ссылке: вход не нужен, разрешение доказывает
        // сама подпись, срок жизни зашит в адрес
        $router->get('/files/notes/{id:\d+}', [NotesController::class, 'sharedFile'])
            ->middleware('signed')
            ->name('notes.file.shared');

        // Готовая фоновая выгрузка: только по подписанной ссылке из уведомления
        $router->get('/exports/{file}', [ExportsController::class, 'download'])
            ->middleware('signed')
            ->name('exports.download');

        // Подтверждение почты открыто и вошедшему: письмо могли открыть позже
        $router->get('/verify/{token}', [RegisterController::class, 'verify'])->name('verify');

        $router->post('/logout', [AuthController::class, 'logout'])->name('logout');

        // Всё остальное — только вошедшим
        $router->group(['middleware' => ['auth']], function (Router $router): void {
            $router->get('/', [HomeController::class, 'index'])->name('home');

            // Свой профиль открыт всем вошедшим: иначе человек без права
            // users.manage не сменил бы себе даже пароль
            $router->get('/profile', [ProfileController::class, 'show'])->name('profile');
            $router->post('/profile', [ProfileController::class, 'update']);
            $router->post('/profile/avatar', [ProfileController::class, 'avatarUpload'])->name('profile.avatar');
            $router->post('/profile/avatar/delete', [ProfileController::class, 'avatarDelete'])->name('profile.avatar.delete');
            $router->post('/profile/password', [ProfileController::class, 'password'])->name('profile.password');
            $router->post('/profile/devices/revoke', [ProfileController::class, 'revoke'])->name('profile.devices.revoke');
            $router->post('/profile/devices/others', [ProfileController::class, 'revokeOthers'])->name('profile.devices.others');

            // Фото пользователя — по id, а не только своё: видно в списке и на
            // чужой карточке
            $router->get('/avatar/{id:\d+}', [ProfileController::class, 'avatar'])->name('avatar.show');

            // Свои уведомления смотрит каждый вошедший: право тут ни при чём,
            // чужих он всё равно не увидит
            $router->group(['prefix' => '/notifications'], function (Router $router): void {
                $router->get('', [NotificationsController::class, 'index'])->name('notifications');
                $router->post('/read', [NotificationsController::class, 'readAll'])->name('notifications.read_all');
                $router->post('/{id:\d+}/read', [NotificationsController::class, 'read'])->name('notifications.read');
                $router->post('/{id:\d+}/delete', [NotificationsController::class, 'delete'])->name('notifications.delete');
            });

            // Демонстрационный раздел: удаляется целиком вместе с моделью,
            // контроллером, шаблонами и миграцией. Прослойка slowlog:300 здесь —
            // тоже часть образца: показывает свою прослойку с аргументом на живом
            // маршруте, разделу она не принадлежит и переносится вместе с ним
            // на любой другой раздел или убирается вовсе
            $router->group(['prefix' => '/notes', 'middleware' => ['slowlog:300']], function (Router $router): void {
                $router->get('', [NotesController::class, 'index'])->middleware('can:notes.view')->name('notes.index');
                $router->get('/export', [NotesController::class, 'export'])->middleware('can:notes.view')->name('notes.export');
                $router->post('/import', [NotesController::class, 'import'])->middleware('can:notes.manage')->name('notes.import');
                $router->post('/import/confirm', [NotesController::class, 'importConfirm'])->middleware('can:notes.manage')->name('notes.import.confirm');
                $router->get('/new', [NotesController::class, 'create'])->middleware('can:notes.manage')->name('notes.create');
                $router->post('/new', [NotesController::class, 'store'])->middleware('can:notes.manage');

                // Ограничение {id:\d+} обязательно: иначе '/notes/new' попал бы сюда
                $router->get('/{id:\d+}', [NotesController::class, 'show'])->middleware('can:notes.view')->name('notes.show');
                $router->get('/{id:\d+}/edit', [NotesController::class, 'edit'])->middleware('can:notes.manage')->name('notes.edit');
                $router->post('/{id:\d+}/edit', [NotesController::class, 'update'])->middleware('can:notes.manage');
                $router->post('/{id:\d+}/delete', [NotesController::class, 'delete'])->middleware('can:notes.manage')->name('notes.delete');
                $router->get('/{id:\d+}/file', [NotesController::class, 'file'])->middleware('can:notes.view')->name('notes.file');
                $router->post('/{id:\d+}/detach', [NotesController::class, 'detach'])->middleware('can:notes.manage')->name('notes.detach');
                $router->get('/{id:\d+}/attachment/{attachment:\d+}', [NotesController::class, 'attachment'])->middleware('can:notes.view')->name('notes.attachment');
                $router->post('/{id:\d+}/comments', [NotesController::class, 'comment'])->middleware('can:notes.view')->name('notes.comment');
                $router->post('/{id:\d+}/comments/delete', [NotesController::class, 'commentDelete'])->middleware('can:notes.view')->name('notes.comment.delete');
            });
        });
    });
};
