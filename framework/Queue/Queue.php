<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Queue;

use Rsgrinko\Proton\Database\Connection;
use Rsgrinko\Proton\Support\ProtonException;
use Rsgrinko\Proton\Support\RequestId;

/**
 * Очередь задач в базе.
 *
 * Задачу нельзя просто «взять и выполнить»: между выборкой и началом работы
 * успевает влезть второй воркер, и задача выполняется дважды. Поэтому строка
 * сначала захватывается — переводится в running с отметкой времени и своим
 * номером попытки, и только захваченная выполняется.
 *
 * Статусы: queued -> running -> done | failed. Временная ошибка возвращает
 * задачу в queued с паузой (backoff), пока не кончатся попытки.
 */
final class Queue
{
    public const DEFAULT_QUEUE = 'default';

    public const QUEUED  = 'queued';
    public const RUNNING = 'running';
    public const DONE    = 'done';
    public const FAILED  = 'failed';

    /** Задача исчерпала попытки: лежит и ждёт, когда разберутся */
    public const DEAD    = 'dead';

    /**
     * Ставит задачу в очередь. Возвращает её номер.
     *
     * @param class-string<Job>    $job
     * @param array<string, mixed> $payload
     */
    public static function push(
        string $job,
        array $payload = [],
        int $delaySeconds = 0,
        ?string $queue = null,
        ?int $priority = null
    ): int {
        if (!is_subclass_of($job, Job::class)) {
            throw new ProtonException('Задача ' . $job . ' не унаследована от ' . Job::class);
        }

        /** @var Job $instance */
        $instance = new $job();

        return Connection::instance()->insert('jobs', [
            'queue'        => $queue ?? $instance->queue(),
            'priority'     => $priority ?? $instance->priority(),
            'job_class'    => $job,
            'payload'      => (string) json_encode($payload, JSON_UNESCAPED_UNICODE),
            'status'       => self::QUEUED,
            'attempts'     => 0,
            'max_attempts' => $instance->attempts(),
            'available_at' => Connection::at(max(0, $delaySeconds)),
            'request_id'   => RequestId::current(),
            'created_at'   => Connection::now(),
            'updated_at'   => Connection::now(),
        ]);
    }

    /**
     * Захватывает одну готовую задачу. null — брать нечего.
     *
     * @return array<string, mixed>|null
     */
    public static function claim(string $queue = self::DEFAULT_QUEUE): ?array
    {
        $db = Connection::instance();

        return $db->transaction(static function (Connection $db) use ($queue): ?array {
            $now = Connection::now();

            // В MySQL строку сразу запираем: без этого два воркера прочитают одну
            // и ту же задачу между SELECT и UPDATE
            // Сначала то, что важнее, при равном приоритете — то, что дольше ждёт
            $sql = 'SELECT * FROM jobs
                    WHERE status = :status AND queue = :queue AND available_at <= :now
                    ORDER BY priority DESC, id LIMIT 1'
                . ($db->isSqlite() ? '' : ' FOR UPDATE SKIP LOCKED');

            $row = $db->selectOne($sql, ['status' => self::QUEUED, 'queue' => $queue, 'now' => $now]);

            if ($row === null) {
                return null;
            }

            $updated = $db->execute(
                'UPDATE jobs SET status = :status, attempts = attempts + 1, reserved_at = :now, updated_at = :now_upd
                 WHERE id = :id AND status = :expected',
                [
                    'status'   => self::RUNNING,
                    'now'      => $now,
                    'now_upd'  => $now,
                    'id'       => (int) $row['id'],
                    'expected' => self::QUEUED,
                ]
            );

            // Ноль строк — задачу успел взять другой процесс (SQLite без SKIP LOCKED)
            if ($updated === 0) {
                return null;
            }

            $row['attempts'] = (int) $row['attempts'] + 1;
            $row['status']   = self::RUNNING;

            return $row;
        });
    }

    /**
     * Задача выполнена.
     *
     * @param array{duration_ms?: int, queries_count?: int|null, queries_ms?: float|null}|null $profile
     */
    public static function complete(int $id, ?array $profile = null): void
    {
        Connection::instance()->update('jobs', array_merge([
            'status'      => self::DONE,
            'finished_at' => Connection::now(),
            'updated_at'  => Connection::now(),
            'error'       => null,
        ], self::profileColumns($profile)), ['id' => $id]);
    }

    /**
     * Задача упала: возвращаем в очередь с паузой или признаём неудавшейся.
     *
     * @param array<string, mixed>                                                             $row
     * @param array{duration_ms?: int, queries_count?: int|null, queries_ms?: float|null}|null  $profile
     */
    public static function fail(array $row, string $error, int $backoff, ?array $profile = null): bool
    {
        $attempts = (int) $row['attempts'];
        $max      = (int) $row['max_attempts'];
        $again    = $attempts < $max;

        Connection::instance()->update('jobs', array_merge([
            // Исчерпала попытки — уходит в «мёртвые»: их разбирают руками,
            // а не повторяют бесконечно
            'status'       => $again ? self::QUEUED : self::DEAD,
            'available_at' => $again ? Connection::at($backoff) : (string) $row['available_at'],
            'error'        => mb_substr($error, 0, 1000),
            'finished_at'  => $again ? null : Connection::now(),
            'updated_at'   => Connection::now(),
        ], self::profileColumns($profile)), ['id' => (int) $row['id']]);

        return $again;
    }

    /**
     * Колонки профиля для UPDATE — своим методом, чтобы complete() и fail()
     * не дублировали одну и ту же сборку.
     *
     * @param array{duration_ms?: int, queries_count?: int|null, queries_ms?: float|null}|null $profile
     *
     * @return array<string, mixed>
     */
    private static function profileColumns(?array $profile): array
    {
        if ($profile === null) {
            return [];
        }

        return [
            'duration_ms'   => $profile['duration_ms'] ?? null,
            'queries_count' => $profile['queries_count'] ?? null,
            'queries_ms'    => $profile['queries_ms'] ?? null,
        ];
    }

    /**
     * Повторить неудавшуюся задачу.
     */
    public static function retry(int $id): bool
    {
        // Счётчик попыток сбрасывается: иначе мёртвая задача умрёт на первом же круге
        return Connection::instance()->update('jobs', [
            'attempts'     => 0,
            'status'       => self::QUEUED,
            'attempts'     => 0,
            'available_at' => Connection::now(),
            'error'        => null,
            'finished_at'  => null,
            'updated_at'   => Connection::now(),
        ], ['id' => $id]) > 0;
    }

    /**
     * Возвращает в очередь задачи, зависшие в running: воркер мог быть убит
     * посреди работы, и без этого они висели бы вечно.
     */
    public static function releaseStuck(int $minutes = 15): int
    {
        return Connection::instance()->execute(
            'UPDATE jobs SET status = :status, updated_at = :now
             WHERE status = :running AND reserved_at < :edge',
            [
                'status'  => self::QUEUED,
                'now'     => Connection::now(),
                'running' => self::RUNNING,
                'edge'    => date('Y-m-d H:i:s', time() - max(1, $minutes) * 60),
            ]
        );
    }

    /**
     * Убирает выполненные задачи старше срока — иначе таблица растёт вечно.
     */
    public static function purge(int $days = 7): int
    {
        if ($days <= 0) {
            return 0;
        }

        return Connection::instance()->execute(
            'DELETE FROM jobs WHERE status = :status AND finished_at < :edge',
            ['status' => self::DONE, 'edge' => date('Y-m-d H:i:s', time() - $days * 86400)]
        );
    }

    /**
     * Сколько задач в каждом состоянии — для страницы состояния и команды.
     *
     * @return array<string, int>
     */
    public static function stats(): array
    {
        $db = Connection::instance();

        if (!$db->hasTable('jobs')) {
            return [];
        }

        $result = [self::QUEUED => 0, self::RUNNING => 0, self::DONE => 0, self::FAILED => 0, self::DEAD => 0];

        foreach ($db->select('SELECT status, COUNT(*) AS total FROM jobs GROUP BY status') as $row) {
            $result[(string) $row['status']] = (int) $row['total'];
        }

        return $result;
    }
}
