<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Install;

use PDO;
use Rsgrinko\Proton\Auth\Crypto;
use Rsgrinko\Proton\Database\Connection;
use Rsgrinko\Proton\Database\Migrator;
use Rsgrinko\Proton\Models\Role;
use Rsgrinko\Proton\Models\User;
use Rsgrinko\Proton\Support\Config;
use Rsgrinko\Proton\Support\ProtonException;
use Throwable;

/**
 * Установка приложения: проверка окружения, запись .env, накат схемы и первый
 * администратор.
 *
 * Один и тот же движок работает и в консоли (`php bin/proton install`), и в вебе
 * (`/install`): мастер только спрашивает и показывает, а делает всё этот класс.
 * Иначе два установщика неизбежно разъезжаются, и веб ставит систему не так,
 * как консоль.
 */
final class Installer
{
    /**
     * Уже установлено: есть ключ, накатаны миграции и заведён хотя бы один
     * пользователь. Пока это не так, установщик открыт — иначе им бы никто
     * не смог воспользоваться.
     *
     * Недоступная база — не «не установлено», а авария: исключение уходит
     * наверх. Раньше здесь был false, и стоило MySQL моргнуть, как /install
     * открывался любому прохожему — тот переписывал DB_* в .env на свою базу
     * и заводил себе администратора. Консольный install ловит это сам: у того,
     * кто сидит в shell, и так есть доступ ко всему.
     *
     * @throws ProtonException база не отвечает (код 503)
     */
    public static function installed(): bool
    {
        if (!Crypto::hasKey() || !is_file(APP_ROOT . '/.env')) {
            return false;
        }

        try {
            $db = Connection::instance();

            if (!$db->hasTable('users')) {
                return false;
            }

            // exists(), а не count(): спрашивается на каждом запросе через
            // auth/guest, и считать всю таблицу ради «есть ли хоть один» незачем
            return User::query()->exists();
        } catch (Throwable $e) {
            throw new ProtonException('База данных недоступна: ' . $e->getMessage(), [], 503, $e);
        }
    }

    /**
     * Проверка окружения перед установкой: версия PHP, расширения, права на запись.
     *
     * @return array<int, array{ok: bool, title: string, value: string, hint: string}>
     */
    public static function requirements(): array
    {
        $checks = [];

        $checks[] = self::check(
            PHP_VERSION_ID >= 80100,
            'Версия PHP',
            PHP_VERSION,
            'Нужен PHP 8.1 или новее'
        );

        foreach (['pdo' => 'работа с базой', 'mbstring' => 'русский текст', 'openssl' => 'шифрование и TLS', 'json' => 'обмен данными'] as $extension => $why) {
            $checks[] = self::check(
                extension_loaded($extension),
                'Расширение ' . $extension,
                extension_loaded($extension) ? 'есть' : 'нет',
                'Нужно для: ' . $why
            );
        }

        $checks[] = self::check(
            extension_loaded('pdo_sqlite') || extension_loaded('pdo_mysql'),
            'Драйверы базы',
            implode(', ', array_values(array_filter([
                extension_loaded('pdo_sqlite') ? 'sqlite' : '',
                extension_loaded('pdo_mysql') ? 'mysql' : '',
            ]))) ?: 'нет',
            'Нужен хотя бы один: php-sqlite3 или php-mysql'
        );

        $checks[] = self::check(
            is_writable(APP_ROOT),
            'Запись в корень проекта',
            is_writable(APP_ROOT) ? 'есть' : 'нет',
            'Установщику нужно создать .env'
        );

        $checks[] = self::check(
            is_dir(APP_ROOT . '/var') && is_writable(APP_ROOT . '/var'),
            'Запись в var/',
            is_dir(APP_ROOT . '/var') && is_writable(APP_ROOT . '/var') ? 'есть' : 'нет',
            'Там живут база SQLite, логи, кэш и загруженные файлы'
        );

        return $checks;
    }

    /**
     * Все ли проверки окружения прошли.
     */
    public static function ready(): bool
    {
        foreach (self::requirements() as $check) {
            if (!$check['ok']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Проверяет, что до базы вообще можно достучаться, и возвращает текст
     * ошибки или null. Без этого мастер записал бы .env с неверными данными
     * и упал уже на миграциях.
     *
     * @param array<string, mixed> $settings
     */
    public static function checkDatabase(array $settings): ?string
    {
        try {
            $connection = new Connection($settings);

            $connection->value('SELECT 1');

            return null;
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }

    /**
     * Пишет .env: берёт .env.example как образец и заменяет в нём значения.
     *
     * Образец не выбрасываем — в нём комментарии, из которых потом понятно,
     * что означает каждая настройка.
     *
     * @param array<string, string> $values
     */
    public static function writeEnv(array $values): void
    {
        $file    = APP_ROOT . '/.env';
        $example = APP_ROOT . '/.env.example';

        $content = is_file($file)
            ? (string) file_get_contents($file)
            : (is_file($example) ? (string) file_get_contents($example) : '');

        foreach ($values as $key => $value) {
            $line = $key . '=' . self::quote($value);

            $content = preg_match('/^' . preg_quote($key, '/') . '=.*$/m', $content) === 1
                ? (string) preg_replace('/^' . preg_quote($key, '/') . '=.*$/m', $line, $content)
                : rtrim($content) . PHP_EOL . $line . PHP_EOL;
        }

        if (@file_put_contents($file, $content) === false) {
            throw new ProtonException('Не удалось записать .env — проверьте права на корень проекта');
        }

        // Дальше в этом же процессе всё должно работать с новыми настройками
        foreach ($values as $key => $value) {
            self::applyToConfig($key, $value);
        }
    }

    /**
     * Ключ приложения: создаётся один раз, существующий не трогаем — иначе
     * зашифрованное станет нечитаемым.
     */
    public static function ensureKey(): string
    {
        $current = (string) Config::get('app.key', '');

        if ($current !== '') {
            return $current;
        }

        $key = Crypto::generateKey();

        self::writeEnv(['APP_KEY' => $key]);

        return $key;
    }

    /**
     * Накатывает схему. Возвращает имена применённых миграций.
     *
     * @return array<int, string>
     */
    public static function migrate(): array
    {
        // Подключение могло открыться со старыми настройками — берём свежее
        Connection::setInstance(null);

        return (new Migrator())->run();
    }

    /**
     * Заводит первого пользователя — администратора.
     */
    public static function createAdmin(string $login, string $password, string $email = '', string $name = ''): User
    {
        if (User::query()->where('login', $login)->exists()) {
            throw new ProtonException('Пользователь с таким логином уже есть');
        }

        return User::register($login, $password, [
            'email'   => $email,
            'name'    => $name !== '' ? $name : $login,
            'role_id' => Role::admin()?->id() ?? 0,
        ]);
    }

    /**
     * Настройки базы из ответов мастера.
     *
     * @param array<string, string> $answers
     *
     * @return array<string, mixed>
     */
    public static function databaseSettings(array $answers): array
    {
        if (($answers['DB_DRIVER'] ?? 'sqlite') !== 'mysql') {
            $path = trim($answers['DB_PATH'] ?? '');

            return [
                'driver' => 'sqlite',
                'sqlite' => ['path' => $path !== '' ? $path : APP_ROOT . '/var/app.sqlite'],
            ];
        }

        return [
            'driver' => 'mysql',
            'mysql'  => [
                'host'     => $answers['DB_HOST'] ?? '127.0.0.1',
                'port'     => (int) ($answers['DB_PORT'] ?? 3306),
                'database' => $answers['DB_DATABASE'] ?? '',
                'username' => $answers['DB_USERNAME'] ?? '',
                'password' => $answers['DB_PASSWORD'] ?? '',
                'charset'  => 'utf8mb4',
            ],
        ];
    }

    /**
     * Заводит базу MySQL, если её ещё нет: обычный шаг установки, из-за которого
     * иначе приходится лезть в консоль сервера.
     *
     * @param array<string, mixed> $settings
     */
    public static function createMysqlDatabase(array $settings): ?string
    {
        $mysql = (array) ($settings['mysql'] ?? []);
        $name  = (string) ($mysql['database'] ?? '');

        if ($name === '' || preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) {
            return 'Недопустимое имя базы: ' . $name;
        }

        try {
            $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', (string) ($mysql['host'] ?? '127.0.0.1'), (int) ($mysql['port'] ?? 3306));

            $pdo = new PDO($dsn, (string) ($mysql['username'] ?? ''), (string) ($mysql['password'] ?? ''), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);

            $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

            return null;
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }

    /**
     * Значение для .env: с пробелами — в кавычках, пустое — как есть.
     */
    private static function quote(string $value): string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_:\/\.\-@,+=]+$/', $value) === 1) {
            return $value;
        }

        return '"' . str_replace('"', '\"', $value) . '"';
    }

    /**
     * Переносит записанное значение в текущую конфигурацию процесса.
     */
    private static function applyToConfig(string $key, string $value): void
    {
        $map = [
            'APP_NAME'     => 'app.name',
            'APP_URL'      => 'app.url',
            'APP_KEY'      => 'app.key',
            'APP_DEBUG'    => 'app.debug',
            'APP_TIMEZONE' => 'app.timezone',
            'DB_DRIVER'    => 'db.driver',
            'DB_PATH'      => 'db.sqlite.path',
            'DB_HOST'      => 'db.mysql.host',
            'DB_PORT'      => 'db.mysql.port',
            'DB_DATABASE'  => 'db.mysql.database',
            'DB_USERNAME'  => 'db.mysql.username',
            'DB_PASSWORD'  => 'db.mysql.password',
            'MAIL_DRIVER'  => 'mail.driver',
            'MAIL_FROM'    => 'mail.from_email',
            'MAIL_FROM_NAME' => 'mail.from_name',
            'SMTP_HOST'    => 'mail.smtp.host',
            'SMTP_PORT'    => 'mail.smtp.port',
            'SMTP_USER'    => 'mail.smtp.username',
            'SMTP_PASSWORD' => 'mail.smtp.password',
            'SMTP_ENCRYPTION' => 'mail.smtp.encryption',
            'MAIL_SERVICE_URL' => 'mail.service.url',
            'MAIL_SERVICE_KEY' => 'mail.service.key',
        ];

        if (!isset($map[$key])) {
            return;
        }

        $path = $map[$key];

        Config::set($path, match (true) {
            in_array($value, ['true', 'false'], true) => $value === 'true',
            ctype_digit($value)                       => (int) $value,
            default                                   => $value,
        });
    }

    /**
     * @return array{ok: bool, title: string, value: string, hint: string}
     */
    private static function check(bool $ok, string $title, string $value, string $hint): array
    {
        return ['ok' => $ok, 'title' => $title, 'value' => $value, 'hint' => $ok ? '' : $hint];
    }
}
