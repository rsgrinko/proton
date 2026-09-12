<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use Rsgrinko\Proton\Auth\Auth;
use Rsgrinko\Proton\Auth\Password;
use Rsgrinko\Proton\Http\Controller;
use Rsgrinko\Proton\Http\Request;
use Rsgrinko\Proton\Http\Response;
use Rsgrinko\Proton\Install\Installer;
use Rsgrinko\Proton\Support\Audit;
use Rsgrinko\Proton\Support\Config;
use Rsgrinko\Proton\View\View;
use Throwable;

/**
 * Веб-установщик: те же шаги, что и у `php bin/proton install`, но мышкой.
 *
 * Открыт, только пока приложение не установлено (нет ключа, схемы или ни одного
 * пользователя) — за этим следит прослойка install. После установки страница
 * уводит на главную: иначе кто угодно завёл бы себе администратора.
 *
 * Делает всё Install\Installer — мастер только спрашивает и показывает.
 */
final class InstallController extends Controller
{
    public function show(Request $request): Response
    {
        return $this->bare('install/index', [
            'checks'  => Installer::requirements(),
            'ready'   => Installer::ready(),
            'values'  => $this->defaults($request),
        ], 'Установка');
    }

    public function store(Request $request): Response
    {
        if (!Installer::ready()) {
            $this->flash('Окружение не готово: посмотрите проверки выше', 'error');

            return $this->redirect('install');
        }

        $data = $this->validate($request, [
            'app_name'       => 'required|max:100',
            'app_url'        => 'required|max:191',
            'timezone'       => 'required|max:64',
            'db_driver'      => 'required|in:sqlite,mysql',
            'db_host'        => 'nullable|max:191',
            'db_port'        => 'nullable|integer',
            'db_database'    => 'nullable|max:100',
            'db_username'    => 'nullable|max:100',
            'db_password'    => 'nullable|max:191',
            'mail_driver'    => 'required|in:mail,smtp,mailer,log,null',
            'mail_from'      => 'nullable|email|max:191',
            'admin_login'    => 'required|min:3|max:100|regex:/^[A-Za-z0-9._-]+$/',
            'admin_email'    => 'nullable|email|max:191',
            'admin_password' => 'required|min:' . Password::minLength() . '|confirmed',
        ], [
            'app_name'       => 'Название',
            'app_url'        => 'Адрес приложения',
            'timezone'       => 'Часовой пояс',
            'db_driver'      => 'База данных',
            'db_database'    => 'Имя базы',
            'mail_driver'    => 'Почта',
            'mail_from'      => 'Адрес отправителя',
            'admin_login'    => 'Логин администратора',
            'admin_email'    => 'Почта администратора',
            'admin_password' => 'Пароль администратора',
        ]);

        $answers = [
            'DB_DRIVER'   => (string) $data['db_driver'],
            'DB_HOST'     => (string) ($data['db_host'] ?? '127.0.0.1'),
            'DB_PORT'     => (string) ($data['db_port'] ?? 3306),
            'DB_DATABASE' => (string) ($data['db_database'] ?? ''),
            'DB_USERNAME' => (string) ($data['db_username'] ?? ''),
            'DB_PASSWORD' => (string) ($data['db_password'] ?? ''),
        ];

        $settings = Installer::databaseSettings($answers);
        $error    = Installer::checkDatabase($settings);

        if ($error !== null && $answers['DB_DRIVER'] === 'mysql') {
            // Базы может просто не быть — заведём её сами, это обычный шаг установки
            if (Installer::createMysqlDatabase($settings) === null) {
                $error = Installer::checkDatabase($settings);
            }
        }

        if ($error !== null) {
            $this->flash('База недоступна: ' . $error, 'error');

            View::stash('old', $request->all());

            return $this->redirect('install');
        }

        try {
            $env = [
                'APP_NAME'     => (string) $data['app_name'],
                'APP_URL'      => rtrim((string) $data['app_url'], '/'),
                'APP_TIMEZONE' => (string) $data['timezone'],
                'APP_ENV'      => 'production',
                'APP_DEBUG'    => 'false',
                'MAIL_DRIVER'  => (string) $data['mail_driver'],
                'MAIL_FROM'    => (string) ($data['mail_from'] ?? ''),
            ];

            if ($answers['DB_DRIVER'] === 'sqlite') {
                $env['DB_DRIVER'] = 'sqlite';
                $env['DB_PATH']   = APP_ROOT . '/var/app.sqlite';
            } else {
                $env = array_merge($env, $answers);
            }

            Installer::writeEnv($env);
            Installer::ensureKey();
            Installer::migrate();

            $user = Installer::createAdmin(
                (string) $data['admin_login'],
                (string) $data['admin_password'],
                (string) ($data['admin_email'] ?? '')
            );

            Auth::login($user, false);

            Audit::created('user', $user->id(), 'установка: заведён администратор ' . (string) $user->login);
        } catch (Throwable $e) {
            $this->flash('Установка не завершилась: ' . $e->getMessage(), 'error');

            View::stash('old', $request->all());

            return $this->redirect('install');
        }

        $this->flash('Готово. Приложение установлено, вы вошли как администратор');

        return $this->redirect('home');
    }

    /**
     * Что подставить в форму: то, что уже есть в настройках.
     *
     * @return array<string, string>
     */
    private function defaults(Request $request): array
    {
        return [
            'app_name'    => (string) Config::get('app.name', 'Proton'),
            'app_url'     => rtrim((string) Config::get('app.url', ''), '/') !== ''
                ? (string) Config::get('app.url')
                : 'http://' . $request->header('host'),
            'timezone'    => (string) Config::get('app.timezone', 'Europe/Moscow'),
            'db_driver'   => (string) Config::get('db.driver', 'sqlite'),
            'db_host'     => (string) Config::get('db.mysql.host', '127.0.0.1'),
            'db_port'     => (string) Config::get('db.mysql.port', 3306),
            'db_database' => (string) Config::get('db.mysql.database', 'proton'),
            'db_username' => (string) Config::get('db.mysql.username', ''),
            'mail_driver' => (string) Config::get('mail.driver', 'mail'),
            'mail_from'   => (string) Config::get('mail.from_email', ''),
            'admin_login' => 'admin',
        ];
    }
}
