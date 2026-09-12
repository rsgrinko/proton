<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Models;

use Rsgrinko\Proton\Database\Connection;

/**
 * Настройки, которые меняются в рантайме: отметки расписания, флаг перезапуска
 * воркера, счётчики обслуживания. Всё, что задаётся при установке, живёт в .env —
 * здесь только то, что пишет само приложение.
 *
 * Модели тут не нужно: это две колонки и три метода.
 */
final class Setting
{
    /**
     * Значение настройки.
     */
    public static function get(string $key, string $default = ''): string
    {
        $db = Connection::instance();

        if (!$db->hasTable('settings')) {
            return $default;
        }

        $value = $db->value('SELECT value FROM settings WHERE setting_key = :key', ['key' => $key]);

        return $value === null ? $default : (string) $value;
    }

    /**
     * Пишет значение — вставкой или обновлением, одним запросом.
     */
    public static function set(string $key, string $value): void
    {
        $db = Connection::instance();

        if (!$db->hasTable('settings')) {
            return;
        }

        // Имена параметров не повторяем: MySQL идёт без эмуляции подготовленных выражений
        $sql = $db->isSqlite()
            ? 'INSERT INTO settings (setting_key, value, updated_at) VALUES (:key, :value, :now)
               ON CONFLICT (setting_key) DO UPDATE SET value = :value_upd, updated_at = :now_upd'
            : 'INSERT INTO settings (setting_key, value, updated_at) VALUES (:key, :value, :now)
               ON DUPLICATE KEY UPDATE value = :value_upd, updated_at = :now_upd';

        $db->execute($sql, [
            'key'       => $key,
            'value'     => $value,
            'now'       => Connection::now(),
            'value_upd' => $value,
            'now_upd'   => Connection::now(),
        ]);
    }

    public static function forget(string $key): void
    {
        $db = Connection::instance();

        if ($db->hasTable('settings')) {
            $db->execute('DELETE FROM settings WHERE setting_key = :key', ['key' => $key]);
        }
    }

    /**
     * Все настройки — их показывает страница состояния.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        $db = Connection::instance();

        if (!$db->hasTable('settings')) {
            return [];
        }

        $result = [];

        foreach ($db->select('SELECT setting_key, value FROM settings ORDER BY setting_key') as $row) {
            $result[(string) $row['setting_key']] = (string) $row['value'];
        }

        return $result;
    }
}
