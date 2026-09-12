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
