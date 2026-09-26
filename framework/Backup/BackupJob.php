<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Backup;

use Rsgrinko\Proton\Access\Permission;
use Rsgrinko\Proton\Notifications\Notify;
use Rsgrinko\Proton\Queue\Job;
use Rsgrinko\Proton\Queue\Queue;
use Rsgrinko\Proton\Support\Logger;

/**
 * Копия базы из очереди: её ставит расписание, а на большой базе — ещё и панель,
 * чтобы страница не ждала дампа.
 *
 * Повторов здесь немного: если база не отдаётся, второй подход через минуту
 * обычно не помогает, а мусорных файлов прибавится.
 */
final class BackupJob extends Job
{
    public function attempts(): int
    {
        return 2;
    }

    public function backoff(int $attempt): int
    {
        return 300;
    }

    /**
     * Копия большой базы со сжатием и проверкой идёт дольше обычных 15 минут —
     * по общему сроку второй воркер снял бы её как зависшую и начал заново.
     */
    public function timeout(): int
    {
        return 3 * 3600;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload): void
    {
        $info    = Backup::create();
        $removed = Backup::rotate();

        (new Logger('backup'))->info('Копия по расписанию готова', [
            'name'    => $info['name'],
            'size'    => $info['size'],
            'removed' => $removed,
        ]);

        // Отправка на FTP — своя задача: сеть до чужого сервера не должна
        // держать дамп, который и так уже готов и годен локально
        if (Ftp::enabled()) {
            Queue::push(ShipBackupJob::class, ['name' => $info['name']]);
        }
    }

    /**
     * Копия не сделалась — это повод сказать людям: молча остаться без копий
     * хуже всего.
     *
     * @param array<string, mixed> $payload
     */
    public function failed(array $payload, string $error): void
    {
        Notify::toPermission(Permission::SYSTEM_MANAGE, new BackupFailedNotification($error));
    }
}
