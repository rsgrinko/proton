<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Console\Commands;

use Rsgrinko\Proton\Auth\Password;
use Rsgrinko\Proton\Console\Command;
use Rsgrinko\Proton\Install\Installer;
use Rsgrinko\Proton\Models\User;
use Rsgrinko\Proton\Support\Config;
use Rsgrinko\Proton\Support\ProtonException;
use Rsgrinko\Proton\Support\Validator;

/**
 * Интерактивная установка.
 *
 * Спрашивает всё, что нужно, и делает всё, что нужно: пишет .env, создаёт ключ,
 * при необходимости заводит базу, накатывает схему и заводит администратора.
 * То же самое умеет веб-мастер на /install — движок у них общий (Install\Installer),
 * иначе два установщика неизбежно разъехались бы.
 *
 * В неинтерактивном запуске (деплой, CI) ответы передаются опциями:
 *   php bin/proton install --force --name="Проект" --url=https://example.com \
 *       --db=mysql --db-host=127.0.0.1 --db-name=proton --db-user=proton --db-password=… \
 *       --admin=admin --admin-password=… --admin-email=admin@example.com
 */
final class InstallCommand extends Command
{
    public function name(): string
    {
        return 'install';
    }

    public function description(): string
    {
        return 'установить приложение: .env, ключ, база, схема, администратор';
    }

    public function usage(): string
    {
        return 'install [--force] [--name=] [--url=] [--db=sqlite|mysql] [--admin=] [--admin-password=]';
    }

    public function run(): int
    {
        $this->line('Установка ' . (string) Config::get('app.name', 'Proton'));
        $this->line('');

        // База не отвечает — в консоли это повод поправить настройки мастером,
        // а не отказ: в вебе то же самое закрыто (см. Installer::installed())
        try {
            $installed = Installer::installed();
        } catch (ProtonException $e) {
            $this->line($e->getMessage() . ' — продолжаю, настройки базы спрошу заново');
            $this->line('');

            $installed = false;
        }

        if ($installed && !$this->hasOption('force')) {
            $this->line('Приложение уже установлено. Повторить установку — с ключом --force');

            return 0;
        }

        if (!$this->requirements()) {
            return 1;
        }

        $env = $this->basics();

        $database = $this->database($env);

        if ($database === null) {
            return 1;
        }

        $env = array_merge($env, $database['env']);
        $env = array_merge($env, $this->mail());

        Installer::writeEnv($env);

        $this->ok('.env записан');

        Installer::ensureKey();

        $this->ok('Ключ приложения на месте');

        $applied = Installer::migrate();

        $this->ok($applied === [] ? 'Схема уже накатана' : 'Применено миграций: ' . count($applied));

        if (!$this->admin()) {
            return 1;
        }

        $this->line('');
        $this->line('Готово. Дальше:');
        $this->line('  php bin/proton serve      — поднять сервер для разработки');
        $this->line('  php bin/proton status     — самопроверка');
        $this->line('  php bin/proton worker     — воркер очереди (на бою — службой)');

        return 0;
    }

    /**
     * Проверка окружения: без неё установка упадёт на середине.
     */
    private function requirements(): bool
    {
        $rows = [];
        $ok   = true;

        foreach (Installer::requirements() as $check) {
            $rows[] = [$check['ok'] ? '[+]' : '[!]', $check['title'], $check['value']];

            if (!$check['ok']) {
                $ok = false;
            }
        }

        $this->table(['', 'Проверка', 'Значение'], $rows);
        $this->line('');

        if ($ok) {
            return true;
        }

        foreach (Installer::requirements() as $check) {
            if ($check['hint'] !== '') {
                $this->fail($check['title'] . ': ' . $check['hint']);
            }
        }

        return false;
    }

    /**
     * Название, адрес, часовой пояс.
     *
     * @return array<string, string>
     */
    private function basics(): array
    {
        $name = (string) $this->option('name', '') ?: $this->ask('Название приложения', (string) Config::get('app.name', 'Proton'));
        $url  = (string) $this->option('url', '') ?: $this->ask('Адрес приложения', (string) Config::get('app.url', 'http://localhost:8000'));
        $zone = (string) $this->option('timezone', '') ?: $this->ask('Часовой пояс', (string) Config::get('app.timezone', 'Europe/Moscow'));

        return [
            'APP_NAME'     => $name,
            'APP_URL'      => rtrim($url, '/'),
            'APP_TIMEZONE' => $zone,
            'APP_ENV'      => (string) $this->option('env', 'production'),
            // На бою отладка выключена: тексты ошибок посторонним видеть незачем
            'APP_DEBUG'    => $this->hasOption('debug') ? 'true' : 'false',
        ];
    }

    /**
     * Настройки базы и проверка, что до неё можно достучаться.
     *
     * @param array<string, string> $env
     *
     * @return array{env: array<string, string>}|null
     */
    private function database(array $env): ?array
    {
        $driver = (string) $this->option('db', '') ?: $this->ask('База данных: sqlite или mysql', 'sqlite');
        $driver = strtolower(trim($driver)) === 'mysql' ? 'mysql' : 'sqlite';

        if ($driver === 'sqlite') {
            $path = (string) $this->option('db-path', '') ?: APP_ROOT . '/var/app.sqlite';

            $settings = Installer::databaseSettings(['DB_DRIVER' => 'sqlite', 'DB_PATH' => $path]);

            $error = Installer::checkDatabase($settings);

            if ($error !== null) {
                $this->fail('Файл базы не открывается: ' . $error);

                return null;
            }

            $this->ok('SQLite: ' . $path);

            return ['env' => ['DB_DRIVER' => 'sqlite', 'DB_PATH' => $path]];
        }

        $answers = [
            'DB_DRIVER'   => 'mysql',
            'DB_HOST'     => (string) $this->option('db-host', '') ?: $this->ask('Хост MySQL', '127.0.0.1'),
            'DB_PORT'     => (string) $this->option('db-port', '') ?: $this->ask('Порт', '3306'),
            'DB_DATABASE' => (string) $this->option('db-name', '') ?: $this->ask('Имя базы', 'proton'),
            'DB_USERNAME' => (string) $this->option('db-user', '') ?: $this->ask('Пользователь', 'root'),
            'DB_PASSWORD' => (string) $this->option('db-password', '') ?: $this->ask('Пароль', ''),
        ];

        $settings = Installer::databaseSettings($answers);

        $error = Installer::checkDatabase($settings);

        if ($error !== null) {
            // Базы может просто не быть — это обычный шаг установки
            $created = Installer::createMysqlDatabase($settings);

            if ($created !== null) {
                $this->fail('Не подключиться к MySQL: ' . $error);

                return null;
            }

            $this->ok('База ' . $answers['DB_DATABASE'] . ' создана');

            $error = Installer::checkDatabase($settings);

            if ($error !== null) {
                $this->fail('Не подключиться к MySQL: ' . $error);

                return null;
            }
        }

        $this->ok('MySQL: ' . $answers['DB_DATABASE'] . ' на ' . $answers['DB_HOST']);

        return ['env' => $answers];
    }

    /**
     * Чем отправлять письма.
     *
     * @return array<string, string>
     */
    private function mail(): array
    {
        $driver = (string) $this->option('mail', '') ?: $this->ask('Почта: mail, smtp, mailer, log или null', 'mail');
        $driver = in_array($driver, ['mail', 'smtp', 'mailer', 'log', 'null'], true) ? $driver : 'mail';

        $from = (string) $this->option('mail-from', '') ?: $this->ask('Адрес отправителя', 'noreply@example.com');

        if (!Validator::isEmail($from)) {
            $this->line('  Адрес отправителя выглядит странно — поправьте MAIL_FROM в .env позже');
        }

        $env = ['MAIL_DRIVER' => $driver, 'MAIL_FROM' => $from];

        if ($driver === 'smtp') {
            $env['SMTP_HOST']       = $this->ask('SMTP-хост', 'smtp.yandex.ru');
            $env['SMTP_PORT']       = $this->ask('SMTP-порт', '587');
            $env['SMTP_USER']       = $this->ask('Пользователь SMTP', $from);
            $env['SMTP_PASSWORD']   = $this->ask('Пароль SMTP', '');
            $env['SMTP_ENCRYPTION'] = $this->ask('Шифрование: tls, ssl или пусто', 'tls');
        }

        if ($driver === 'mailer') {
            $env['MAIL_SERVICE_URL'] = $this->ask('Адрес почтового сервиса', '');
            $env['MAIL_SERVICE_KEY'] = $this->ask('Ключ проекта', '');
        }

        return $env;
    }

    /**
     * Первый пользователь — администратор.
     */
    private function admin(): bool
    {
        if (User::query()->count() > 0) {
            $this->ok('Пользователи уже есть — администратора не завожу');

            return true;
        }

        $login = (string) $this->option('admin', '') ?: $this->ask('Логин администратора', 'admin');
        $email = (string) $this->option('admin-email', '') ?: $this->ask('Почта администратора', '');

        $password  = (string) $this->option('admin-password', '');
        $generated = false;

        if ($password === '') {
            $password = $this->ask('Пароль (пусто — придумаю сам)', '');
        }

        if ($password === '') {
            $password  = Password::generate();
            $generated = true;
        }

        $error = Password::check($password);

        if ($error !== null) {
            $this->fail($error);

            return false;
        }

        $user = Installer::createAdmin($login, $password, $email);

        $this->ok('Администратор ' . (string) $user->login . ' заведён');

        if ($generated) {
            $this->line('  Пароль: ' . $password);
            $this->line('  Он больше нигде не сохранён — запишите сейчас');
        }

        return true;
    }
}
