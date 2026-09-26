<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Support;

use Rsgrinko\Proton\Auth\Crypto;
use Rsgrinko\Proton\Backup\Backup;
use Rsgrinko\Proton\Core\Updater;
use Rsgrinko\Proton\Database\Connection;
use Rsgrinko\Proton\Database\Migrator;
use Rsgrinko\Proton\Files\Storage;
use Rsgrinko\Proton\Models\Role;
use Rsgrinko\Proton\Models\Setting;
use Rsgrinko\Proton\Models\User;
use Rsgrinko\Proton\Models\Webhook;
use Rsgrinko\Proton\Models\WebhookDelivery;
use Rsgrinko\Proton\Queue\Queue;
use Rsgrinko\Proton\Webhooks\Incoming;
use Throwable;

/**
 * Самопроверка: что с окружением, базой, ключами, очередью и правами на каталоги.
 *
 * Проверки только читают и не лезут в сеть: страница состояния должна открываться
 * быстро и не иметь побочных действий. Каждая возвращает уровень, заголовок,
 * значение и — если что-то не так — подсказку, что делать.
 */
final class Diagnostics
{
    public const OK    = 'ok';
    public const WARN  = 'warn';
    public const ERROR = 'error';

    /**
     * Все проверки.
     *
     * @return array<int, array{level: string, title: string, value: string, hint: string}>
     */
    public static function run(): array
    {
        return array_merge(
            self::environment(),
            self::storage(),
            self::database(),
            self::application()
        );
    }

    /**
     * Худший уровень из всех проверок — по нему красится плашка в меню.
     *
     * @param array<int, array{level: string, title: string, value: string, hint: string}> $checks
     */
    public static function worst(array $checks): string
    {
        foreach ([self::ERROR, self::WARN] as $level) {
            foreach ($checks as $check) {
                if ($check['level'] === $level) {
                    return $level;
                }
            }
        }

        return self::OK;
    }

    /**
     * @return array<int, array{level: string, title: string, value: string, hint: string}>
     */
    private static function environment(): array
    {
        $checks = [];

        $checks[] = self::check(
            PHP_VERSION_ID >= 80100,
            'Версия PHP',
            PHP_VERSION,
            'Нужен PHP 8.1 или новее'
        );

        // Не про «пройдено/не пройдено» — просто откуда посмотреть, что
        // нового в ядре: php bin/proton core:latest, docs/CORE_UPDATES.md
        $checks[] = self::check(true, 'Версия ядра', (new Updater(APP_ROOT))->localVersion(), '');

        foreach (['pdo', 'mbstring', 'openssl', 'json'] as $extension) {
            $checks[] = self::check(
                extension_loaded($extension),
                'Расширение ' . $extension,
                extension_loaded($extension) ? 'есть' : 'нет',
                'Установите php-' . $extension
            );
        }

        return $checks;
    }

    /**
     * @return array<int, array{level: string, title: string, value: string, hint: string}>
     */
    private static function storage(): array
    {
        $checks = [];

        foreach (['paths.log' => 'Каталог логов', 'paths.cache' => 'Каталог кэша', 'paths.storage' => 'Хранилище файлов'] as $key => $title) {
            $dir = (string) Config::get($key, '');

            $checks[] = self::check(
                $dir !== '' && is_dir($dir) && is_writable($dir),
                $title,
                $dir === '' ? 'не задан' : $dir,
                'Каталог должен существовать и быть доступным на запись пользователю веб-сервера'
            );
        }

        $free = @disk_free_space(APP_ROOT);

        if ($free !== false) {
            $checks[] = self::check(
                $free > 200 * 1024 * 1024,
                'Место на диске',
                Str::bytes((int) $free) . ' свободно',
                'Меньше 200 МБ: логи и загрузки скоро перестанут писаться',
                self::WARN
            );
        }

        $checks[] = [
            'level' => self::OK,
            'title' => 'Занято хранилищем',
            'value' => Str::bytes(Storage::usedBytes()),
            'hint'  => '',
        ];

        return $checks;
    }

    /**
     * @return array<int, array{level: string, title: string, value: string, hint: string}>
     */
    private static function database(): array
    {
        $checks = [];

        try {
            $db = Connection::instance();

            $checks[] = self::check(true, 'База данных', $db->driver() . ', подключение есть', '');

            $migrator = new Migrator();
            $pending  = $migrator->pending();

            $checks[] = self::check(
                $pending === [],
                'Миграции',
                $pending === [] ? 'все применены' : 'ждут: ' . implode(', ', $pending),
                'Накатите: php bin/proton migrate'
            );

            $unknown = $migrator->unknown();

            if ($unknown !== []) {
                $checks[] = [
                    'level' => self::WARN,
                    'title' => 'Лишние миграции',
                    'value' => implode(', ', $unknown),
                    'hint'  => 'Эти миграции есть в базе, но не в коде — похоже на откат релиза',
                ];
            }

            $checks[] = self::check(
                User::query()->where('active', 1)->count() > 0,
                'Пользователи',
                (string) User::query()->count() . ' всего',
                'Нет ни одного активного пользователя — войти будет некому'
            );
        } catch (Throwable $e) {
            $checks[] = [
                'level' => self::ERROR,
                'title' => 'База данных',
                'value' => $e->getMessage(),
                'hint'  => 'Проверьте настройки DB_* в .env',
            ];
        }

        return $checks;
    }

    /**
     * @return array<int, array{level: string, title: string, value: string, hint: string}>
     */
    private static function application(): array
    {
        $checks = [];

        $checks[] = self::check(
            Crypto::hasKey(),
            'Ключ приложения',
            Crypto::hasKey() ? 'задан' : 'не задан',
            'Создайте: php bin/proton app:key — без него не работают подписанные куки'
        );

        $checks[] = self::check(
            !(bool) Config::get('app.debug', false),
            'Режим отладки',
            (bool) Config::get('app.debug', false) ? 'включён' : 'выключен',
            'На бою APP_DEBUG должен быть false: иначе тексты ошибок видны посторонним',
            self::WARN
        );

        $checks[] = self::check(
            trim((string) Config::get('app.url', '')) !== '',
            'Адрес приложения',
            (string) Config::get('app.url', 'не задан'),
            'APP_URL нужен ссылкам в письмах — без него они собираются неверно',
            self::WARN
        );

        try {
            $stats  = Queue::stats();
            $failed = (int) ($stats[Queue::FAILED] ?? 0);

            $checks[] = self::check(
                $failed === 0,
                'Очередь задач',
                'в очереди ' . (int) ($stats[Queue::QUEUED] ?? 0) . ', с ошибкой ' . $failed,
                'Разберите неудавшиеся задачи: php bin/proton queue:status',
                self::WARN
            );

            $heartbeat = (int) Setting::get('worker:maintenance', '0');

            if ($heartbeat > 0) {
                $checks[] = self::check(
                    time() - $heartbeat < 3600,
                    'Воркер',
                    'последний круг ' . date('d.m.Y H:i', $heartbeat),
                    'Воркер молчит больше часа — проверьте службу',
                    self::WARN
                );
            } else {
                $checks[] = [
                    'level' => self::WARN,
                    'title' => 'Воркер',
                    'value' => 'ни разу не запускался',
                    'hint'  => 'Запустите: php bin/proton worker (или настройте службу)',
                ];
            }
        } catch (Throwable $e) {
            $checks[] = ['level' => self::WARN, 'title' => 'Очередь задач', 'value' => $e->getMessage(), 'hint' => ''];
        }

        $checks[] = [
            'level' => self::OK,
            'title' => 'Почта',
            'value' => (string) Config::get('mail.driver', 'mail') . ', от ' . (string) Config::get('mail.from_email', 'не задан'),
            'hint'  => '',
        ];

        if ((bool) Config::get('auth.registration', true)) {
            try {
                $problem = Role::registrationProblem();

                $checks[] = self::check(
                    $problem === '',
                    'Роль при регистрации',
                    $problem === '' ? (string) Config::get('auth.registration_role', '') : $problem,
                    'Регистрация закрыта, пока роль не поправят: AUTH_REGISTRATION_ROLE — имя роли без прав на управление',
                    self::WARN
                );
            } catch (Throwable $e) {
                $checks[] = ['level' => self::WARN, 'title' => 'Роль при регистрации', 'value' => $e->getMessage(), 'hint' => ''];
            }
        }

        $checks[] = self::webhooks();
        $checks[] = self::incoming();
        $checks[] = self::backups();

        return $checks;
    }

    /**
     * Входящие источники без токена принимают посылку от кого угодно — так
     * задумано для систем, которые ничего не умеют слать, но держать это
     * нужно на виду: забытый пустой токен выглядит точно так же.
     *
     * @return array{level: string, title: string, value: string, hint: string}
     */
    private static function incoming(): array
    {
        $open = [];

        foreach (Incoming::sources() as $key => $source) {
            if ($source['token'] === '') {
                $open[] = $key;
            }
        }

        return self::check(
            $open === [],
            'Входящие вебхуки',
            $open === [] ? 'источников ' . count(Incoming::sources()) . ', все с токеном' : 'без токена: ' . implode(', ', $open),
            'Эти адреса принимают посылку от любого — если это не нарочно, задайте токен в config/incoming.php',
            self::WARN
        );
    }

    /**
     * Подписки на события: сколько их и нет ли недоставленных посылок.
     *
     * @return array{level: string, title: string, value: string, hint: string}
     */
    private static function webhooks(): array
    {
        try {
            if (!Connection::instance()->hasTable('webhooks')) {
                return ['level' => self::OK, 'title' => 'Вебхуки', 'value' => 'таблиц нет', 'hint' => ''];
            }

            $active = Webhook::query()->where('active', 1)->count();
            $failed = (int) (WebhookDelivery::stats()[WebhookDelivery::FAILED] ?? 0);

            return self::check(
                $failed === 0,
                'Вебхуки',
                'подписок ' . $active . ', не доставлено ' . $failed,
                'Посмотрите журнал доставок: кто-то из подписчиков не отвечает',
                self::WARN
            );
        } catch (Throwable $e) {
            return ['level' => self::WARN, 'title' => 'Вебхуки', 'value' => $e->getMessage(), 'hint' => ''];
        }
    }

    /**
     * Копии базы: есть ли они вообще и давно ли делались.
     *
     * @return array{level: string, title: string, value: string, hint: string}
     */
    private static function backups(): array
    {
        try {
            $files = Backup::files();

            if ($files === []) {
                return [
                    'level' => self::WARN,
                    'title' => 'Копии базы',
                    'value' => 'копий нет',
                    'hint'  => 'Сделайте: php bin/proton backup:create, и задайте BACKUP_SCHEDULE',
                ];
            }

            $last = strtotime($files[0]['created']) ?: 0;
            $days = (int) floor((time() - $last) / 86400);

            return self::check(
                $days <= 2,
                'Копии базы',
                count($files) . ', свежая от ' . date('d.m.Y H:i', $last),
                'Свежей копии нет уже ' . $days . ' дней — проверьте расписание и воркер',
                self::WARN
            );
        } catch (Throwable $e) {
            return ['level' => self::WARN, 'title' => 'Копии базы', 'value' => $e->getMessage(), 'hint' => ''];
        }
    }

    /**
     * @return array{level: string, title: string, value: string, hint: string}
     */
    private static function check(bool $passed, string $title, string $value, string $hint, string $failLevel = self::ERROR): array
    {
        return [
            'level' => $passed ? self::OK : $failLevel,
            'title' => $title,
            'value' => $value,
            'hint'  => $passed ? '' : $hint,
        ];
    }
}
