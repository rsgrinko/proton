<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Support;

/**
 * Режим обслуживания: пока флаг лежит на диске, всем чужим — 503, вместо
 * того чтобы ловить приложение на середине наката миграции или деплоя.
 *
 *     php bin/proton down --message="Обновляем базу" --allow=127.0.0.1 --retry=60
 *     php bin/proton up
 *
 * Флаг — файл, не запись в базе: база в момент наката как раз может быть
 * недоступна или в переходном состоянии, а до файла дело есть только у диска.
 * Тем, у кого право `system.manage`, включённый режим не мешает — не нужно
 * держать отдельный список адресов ради своей же правки. Список `allow` —
 * для тех, кому нельзя увидеть панель (внешний heartbeat, вебхук).
 */
final class Maintenance
{
    private static function file(): string
    {
        return (string) Config::get('paths.maintenance', APP_ROOT . '/var') . '/maintenance.json';
    }

    /**
     * Включить. Пустой список `allow` — никого, кроме `system.manage`.
     */
    public static function activate(string $message = '', string $allow = '', int $retry = 0): void
    {
        $file = self::file();

        @mkdir(dirname($file), 0775, true);

        file_put_contents($file, (string) json_encode([
            'message' => $message,
            'allow'   => $allow,
            'retry'   => max(0, $retry),
            'since'   => time(),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    public static function deactivate(): void
    {
        @unlink(self::file());
    }

    public static function active(): bool
    {
        return is_file(self::file());
    }

    /**
     * Разрешён ли адресу проход мимо страницы «идут работы».
     */
    public static function allows(string $ip): bool
    {
        $allow = trim((string) (self::payload()['allow'] ?? ''));

        return $allow !== '' && IpAllowlist::allows($allow, $ip);
    }

    /**
     * @return array{message: string, allow: string, retry: int, since: int}
     */
    public static function payload(): array
    {
        if (!self::active()) {
            return ['message' => '', 'allow' => '', 'retry' => 0, 'since' => 0];
        }

        $decoded = json_decode((string) file_get_contents(self::file()), true);

        return [
            'message' => (string) ($decoded['message'] ?? ''),
            'allow'   => (string) ($decoded['allow'] ?? ''),
            'retry'   => (int) ($decoded['retry'] ?? 0),
            'since'   => (int) ($decoded['since'] ?? 0),
        ];
    }
}
