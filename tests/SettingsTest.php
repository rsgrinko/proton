<?php

declare(strict_types=1);

/**
 * Настройки из панели: реестр, значения поверх `.env`, сброс и проверка ввода.
 */

use Rsgrinko\Proton\Models\Setting;
use Rsgrinko\Proton\Support\Config;
use Rsgrinko\Proton\Support\ProtonException;
use Rsgrinko\Proton\Support\Settings;

test('настройки: реестр знает свои и чужие ключи', function (): void {
    assertTrue(Settings::known('ui.per_page'), 'настройка ядра');
    assertTrue(Settings::known('notes.per_user'), 'настройка приложения из config/settings.php');
    assertFalse(Settings::known('выдуманная.настройка'));

    $item = assertNotNull(Settings::describe('mail.driver'));

    assertSame(Settings::SELECT, $item['type']);
    assertTrue(array_key_exists('smtp', $item['options']));
});

test('настройки: значение из базы ложится поверх .env', function (): void {
    withOwnDatabase(static function (): void {
        withConfig(['ui.per_page' => 25], static function (): void {
            assertFalse(Settings::overridden('ui.per_page'));
            assertSame(25, Settings::value('ui.per_page'));

            Settings::put('ui.per_page', '7');

            assertTrue(Settings::overridden('ui.per_page'));
            assertSame(7, Settings::value('ui.per_page'), 'тип приводится по реестру');
            assertSame(7, Config::get('ui.per_page'), 'значение применилось сразу');

            // В базе лежит строка под своим префиксом, рядом с отметками ядра
            assertSame('7', Setting::get(Settings::PREFIX . 'ui.per_page'));
        });
    });
});

test('настройки: новый процесс подхватывает значения сам', function (): void {
    withOwnDatabase(static function (): void {
        withConfig(['auth.registration' => true], static function (): void {
            Setting::set(Settings::PREFIX . 'auth.registration', '0');

            Settings::reset();
            Settings::apply();

            assertFalse((bool) Config::get('auth.registration'), 'галочка из базы выключила регистрацию');
        });
    });
});

test('настройки: сброс возвращает значение из .env', function (): void {
    withOwnDatabase(static function (): void {
        Settings::put('mail.from_name', 'Из базы');

        assertSame('Из базы', Settings::value('mail.from_name'));

        Settings::forget('mail.from_name');

        assertFalse(Settings::overridden('mail.from_name'));
        assertSame('', Setting::get(Settings::PREFIX . 'mail.from_name'), 'строки в базе больше нет');

        // Дальше работает то, что в .env, — правка файла снова имеет смысл
        assertSame(Config::get('mail.from_name'), Settings::value('mail.from_name'));
    });
});

test('настройки: чужой ключ в базу не попадёт', function (): void {
    withOwnDatabase(static function (): void {
        assertThrows(static function (): void {
            Settings::put('db.driver', 'postgres');
        }, 'настройки вне реестра не принимаются');

        assertSame('', Setting::get(Settings::PREFIX . 'db.driver'));
    });
});

test('настройки: ввод проверяется по типу', function (): void {
    assertSame('Нужно целое число', Settings::problem('ui.per_page', 'много'));
    assertSame('', Settings::problem('ui.per_page', '50'));
    assertSame('', Settings::problem('ui.per_page', '-1'), 'минус тоже число: границы — дело читающего');

    assertSame('Значение не из списка', Settings::problem('mail.driver', 'голубь'));
    assertSame('', Settings::problem('mail.driver', 'smtp'));

    assertSame('Неизвестная настройка', Settings::problem('нет.такой', '1'));
});

test('настройки: булевы хранятся как ноль и единица', function (): void {
    withOwnDatabase(static function (): void {
        // put() меняет и конфигурацию процесса, поэтому возвращаем её соседям
        // в прежнем виде: иначе выключенные здесь вебхуки останутся выключенными
        withConfig(['webhooks.enabled' => true], static function (): void {
            Settings::put('webhooks.enabled', 'on');

            assertSame('1', Setting::get(Settings::PREFIX . 'webhooks.enabled'));
            assertTrue((bool) Settings::value('webhooks.enabled'));

            Settings::put('webhooks.enabled', '0');

            assertSame('0', Setting::get(Settings::PREFIX . 'webhooks.enabled'));
            assertFalse((bool) Settings::value('webhooks.enabled'));
        });
    });
});

test('настройки: база недоступна — работаем на .env', function (): void {
    withOwnDatabase(static function (): void {
        withConfig(['app.name' => 'Из файла'], static function (): void {
            Settings::put('app.name', 'Из базы');
            Settings::reset();

            // Таблицы нет вовсе: apply() не должен ронять приложение
            Rsgrinko\Proton\Database\Connection::instance()->execute('DROP TABLE settings');

            Config::set('app.name', 'Из файла');

            Settings::apply();

            assertSame('Из файла', Config::get('app.name'));
        });
    });
});

/**
 * Задача, которая запоминает, какое значение настройки увидела.
 */
final class SettingsRefreshTestJob extends Rsgrinko\Proton\Queue\Job
{
    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload): void
    {
        Setting::set('test:refresh:seen', (string) Config::get('ui.per_page'));
    }
}

test('настройки: refresh() подхватывает правку из другого процесса', function (): void {
    withOwnDatabase(static function (): void {
        withConfig(['ui.per_page' => 25], static function (): void {
            Settings::apply();

            assertSame(25, Config::get('ui.per_page'));

            // Панель записала значение в базу — этот процесс о нём не знает
            Setting::set(Settings::PREFIX . 'ui.per_page', '11');

            assertSame(25, Config::get('ui.per_page'), 'без refresh() держится прежнее');

            Settings::refresh();

            assertSame(11, Config::get('ui.per_page'));
        });
    });
});

test('настройки: refresh() возвращает сброшенную настройку к .env', function (): void {
    withOwnDatabase(static function (): void {
        withConfig(['ui.per_page' => 25], static function (): void {
            Setting::set(Settings::PREFIX . 'ui.per_page', '11');

            Settings::refresh();

            assertSame(11, Config::get('ui.per_page'));

            // Панель сбросила значение: строки в базе больше нет
            Setting::forget(Settings::PREFIX . 'ui.per_page');

            Settings::refresh();

            $fromEnv = ((array) require APP_ROOT . '/config/config.php')['ui']['per_page'] ?? null;

            assertSame($fromEnv, Config::get('ui.per_page'), 'значение снова из .env');
            assertFalse(Settings::overridden('ui.per_page'));
        });
    });
});

test('настройки: refresh() не трогает то, что база не перекрывала', function (): void {
    withOwnDatabase(static function (): void {
        withConfig(['ui.per_page' => 25, 'app.name' => 'Тестовое имя'], static function (): void {
            Setting::set(Settings::PREFIX . 'ui.per_page', '11');

            Settings::refresh();
            Settings::refresh();

            assertSame('Тестовое имя', Config::get('app.name'), 'значение не из базы остаётся как было');
        });
    });
});

test('настройки: воркер видит правку из панели без перезапуска', function (): void {
    withOwnDatabase(static function (): void {
        withConfig(['ui.per_page' => 25], static function (): void {
            Settings::apply();

            Setting::set(Settings::PREFIX . 'ui.per_page', '13');
            Rsgrinko\Proton\Queue\Queue::push(SettingsRefreshTestJob::class);

            (new Rsgrinko\Proton\Queue\Worker())->run(true);

            assertSame('13', Setting::get('test:refresh:seen'));
        });
    });
});

test('настройки: дробное число принимает точку и запятую, хранится с точкой', function (): void {
    withOwnDatabase(static function (): void {
        Settings::register('test.ratio', 'Доля', Settings::FLOAT, 'Тест');

        assertSame('', Settings::problem('test.ratio', '0.7'));
        assertSame('', Settings::problem('test.ratio', '0,7'));
        assertSame('', Settings::problem('test.ratio', '-2'));
        assertTrue(Settings::problem('test.ratio', 'семь') !== '');
        assertTrue(Settings::problem('test.ratio', '1.2.3') !== '');

        Settings::put('test.ratio', '0,7');

        assertSame('0.7', Setting::get(Settings::PREFIX . 'test.ratio'));
        assertSame(0.7, Config::get('test.ratio'));

        Settings::refresh();

        assertSame(0.7, Settings::value('test.ratio'), 'из базы читается числом');
    });
});

test('настройки: Env::float() читает и точку, и запятую', function (): void {
    putenv('PROTON_TEST_RATIO_DOT=0.25');
    putenv('PROTON_TEST_RATIO_COMMA=0,7');
    putenv('PROTON_TEST_RATIO_EMPTY=');

    try {
        assertSame(0.25, Rsgrinko\Proton\Support\Env::float('PROTON_TEST_RATIO_DOT'));
        assertSame(0.7, Rsgrinko\Proton\Support\Env::float('PROTON_TEST_RATIO_COMMA'));
        assertSame(1.5, Rsgrinko\Proton\Support\Env::float('PROTON_TEST_RATIO_EMPTY', 1.5), 'пусто — значение по умолчанию');
        assertSame(2.5, Rsgrinko\Proton\Support\Env::float('PROTON_TEST_RATIO_NONE', 2.5));
    } finally {
        putenv('PROTON_TEST_RATIO_DOT');
        putenv('PROTON_TEST_RATIO_COMMA');
        putenv('PROTON_TEST_RATIO_EMPTY');
    }
});
