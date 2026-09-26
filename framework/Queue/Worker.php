<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Queue;

use Rsgrinko\Proton\Backup\Backup;
use Rsgrinko\Proton\Cache\Cache;
use Rsgrinko\Proton\Database\Connection;
use Rsgrinko\Proton\Models\AuditEntry;
use Rsgrinko\Proton\Models\BlockedIp;
use Rsgrinko\Proton\Models\IncomingHook;
use Rsgrinko\Proton\Models\RememberToken;
use Rsgrinko\Proton\Models\SecurityEvent;
use Rsgrinko\Proton\Models\Setting;
use Rsgrinko\Proton\Models\UserSession;
use Rsgrinko\Proton\Models\WebhookDelivery;
use Rsgrinko\Proton\Notifications\Notify;
use Rsgrinko\Proton\RateLimit\RateLimiter;
use Rsgrinko\Proton\Support\Config;
use Rsgrinko\Proton\Support\ExportFile;
use Rsgrinko\Proton\Support\Logger;
use Rsgrinko\Proton\Support\Profiler;
use Rsgrinko\Proton\Support\Trash;
use Rsgrinko\Proton\Support\RequestId;
use Throwable;

/**
 * Воркер очереди: разбирает задачи, ведёт расписание и подчищает за приложением.
 *
 * Запускается демоном (systemd) или по cron с ключом --once. После выкладки кода
 * воркер нужно перезапускать: он держит загруженные классы в памяти, и веб-часть
 * уже отвечает по-новому, пока задачи выполняются старым кодом. Флаг перезапуска
 * ставится командой worker:restart — воркер доработает круг и выйдет сам.
 */
final class Worker
{
    /** Ключ в settings, по которому воркеру говорят «выйди» */
    public const RESTART_KEY = 'worker:restart';

    private Logger $logger;

    /** @var array<int, string> Очереди в порядке важности: сначала первая */
    private array $queues;

    private bool $stopping = false;

    /** Когда последний раз заглядывали в обслуживание и расписание */
    private int $lastTick = 0;

    /**
     * Очередей может быть несколько: `worker --queue=default,webhooks`. Порядок
     * важен — задача из очереди слева берётся раньше, и медленные посылки
     * подписчикам не задерживают письма.
     */
    public function __construct(string $queue = Queue::DEFAULT_QUEUE)
    {
        $this->logger = new Logger('worker');
        $this->queues = self::parseQueues($queue);
    }

    /**
     * Разбирает «default,webhooks» в список без пустых и без повторов.
     *
     * @return array<int, string>
     */
    public static function parseQueues(string $queue): array
    {
        $names = array_filter(array_map('trim', explode(',', $queue)), static fn (string $name): bool => $name !== '');

        return $names === [] ? [Queue::DEFAULT_QUEUE] : array_values(array_unique($names));
    }

    /**
     * Основной цикл. $once — разобрать всё готовое и выйти (для cron).
     *
     * @return array{done: int, failed: int}
     */
    public function run(bool $once = false): array
    {
        $this->listenSignals();

        $startedAt = time();
        $done      = 0;
        $failed    = 0;

        $sleep     = max(1, (int) Config::get('queue.sleep', 3));
        $lifetime  = max(0, (int) Config::get('queue.worker_lifetime', 3600));
        $restartAt = (int) Setting::get(self::RESTART_KEY, '0');

        $this->logger->info('Воркер запущен', ['queue' => implode(',', $this->queues), 'once' => $once]);

        do {
            $job = $this->claim();

            if ($job !== null) {
                $this->perform($job) ? $done++ : $failed++;

                // Расписание не ждёт, пока очередь опустеет: под непрерывным
                // потоком вебхуков копии и присмотр иначе не случились бы вовсе.
                // Между задачами — не чаще раза в паузу воркера
                if (time() - $this->lastTick >= $sleep) {
                    $this->maintenance();
                }

                if ($this->stopping) {
                    break;
                }

                continue;
            }

            // Очередь пуста — самое время заняться обслуживанием
            $this->maintenance();

            if ($once || $this->stopping) {
                break;
            }

            if ($lifetime > 0 && time() - $startedAt >= $lifetime) {
                $this->logger->info('Воркер отработал своё время и выходит');

                break;
            }

            if ((int) Setting::get(self::RESTART_KEY, '0') > $restartAt) {
                $this->logger->info('Получен запрос на перезапуск');

                break;
            }

            sleep($sleep);
        } while (true);

        $this->logger->info('Воркер остановлен', ['done' => $done, 'failed' => $failed]);

        return ['done' => $done, 'failed' => $failed];
    }

    /**
     * Просит работающий воркер выйти: systemd поднимет его заново уже с новым кодом.
     */
    public static function requestRestart(): void
    {
        Setting::set(self::RESTART_KEY, (string) time());
    }

    /**
     * Берёт задачу из первой очереди, где она есть.
     *
     * @return array<string, mixed>|null
     */
    private function claim(): ?array
    {
        foreach ($this->queues as $queue) {
            $job = Queue::claim($queue);

            if ($job !== null) {
                return $job;
            }
        }

        return null;
    }

    /**
     * Выполняет одну задачу. true — получилось.
     *
     * @param array<string, mixed> $row
     */
    private function perform(array $row): bool
    {
        // Своя цепочка на каждую задачу: соседние задачи не должны сливаться
        // в одну строку логов
        RequestId::set((string) ($row['request_id'] ?? ''));

        $class   = (string) $row['job_class'];
        $payload = json_decode((string) $row['payload'], true);
        $payload = is_array($payload) ? $payload : [];

        if (!class_exists($class) || !is_subclass_of($class, Job::class)) {
            Queue::fail($row, 'Класс задачи не найден: ' . $class, 0);

            $this->logger->error('Задача без класса', ['id' => $row['id'], 'class' => $class]);

            return false;
        }

        /** @var Job $job */
        $job = new $class();

        // Своя пачка запросов на каждую задачу: иначе счётчик копится
        // с предыдущей и цифры ничего не значат
        Profiler::reset();

        $startedAt = microtime(true);

        try {
            $job->handle($payload);

            $profile = $this->profile($startedAt);

            // Отметка об успехе и следующий шаг цепочки — одной транзакцией:
            // не бывает состояния «выполнена, а шаг не встал». Не встал шаг —
            // задача считается упавшей и повторится (Job обязан переживать
            // повтор), а на повторе шаг поставится снова
            Connection::instance()->transaction(static function () use ($row, $profile, $payload, $job): void {
                Queue::complete((int) $row['id'], $profile);
                Queue::continueChain($payload, $job);
            });

            $this->logger->info('Задача выполнена', [
                'id'      => (int) $row['id'],
                'job'     => $class,
                'ms'      => $profile['duration_ms'],
                'queries' => $profile['queries_count'],
            ]);

            return true;
        } catch (Throwable $e) {
            $profile = $this->profile($startedAt);

            $attempt = (int) $row['attempts'];
            $again   = Queue::fail($row, $e->getMessage(), $job->backoff($attempt), $profile);

            $this->logger->error('Задача упала', [
                'id'      => (int) $row['id'],
                'job'     => $class,
                'attempt' => $attempt,
                'again'   => $again,
                'error'   => $e->getMessage(),
            ]);

            if (!$again) {
                try {
                    $job->failed($payload, $e->getMessage());
                } catch (Throwable $inner) {
                    $this->logger->error('Обработчик отказа тоже упал', ['error' => $inner->getMessage()]);
                }
            }

            return false;
        }
    }

    /**
     * Сколько заняла задача и сколько сходила в базу. Запросы считаются, только
     * когда включён APP_DEBUG — на бою это лишняя память на каждую задачу,
     * а вот время выполнения ничего не стоит и нужно всегда.
     *
     * @return array{duration_ms: int, queries_count: int|null, queries_ms: float|null}
     */
    private function profile(float $startedAt): array
    {
        return [
            'duration_ms'   => (int) round((microtime(true) - $startedAt) * 1000),
            'queries_count' => Profiler::enabled() ? Profiler::count() : null,
            'queries_ms'    => Profiler::enabled() ? Profiler::time() : null,
        ];
    }

    /**
     * Уборка и расписание — то, ради чего в других проектах держат cron.
     */
    private function maintenance(): void
    {
        $this->lastTick = time();

        $interval = max(60, (int) Config::get('queue.maintenance_interval', 300));

        try {
            $last = (int) Setting::get('worker:maintenance', '0');

            if (time() - $last < $interval) {
                // Расписание проверяем чаще уборки: у задач может быть свой минутный шаг
                Scheduler::run();

                return;
            }
        } catch (Throwable $e) {
            // База моргнула — воркер не должен падать из-за расписания,
            // следующий круг попробует снова
            $this->logger->error('Расписание не доехало', ['error' => $e->getMessage()]);

            return;
        }

        Setting::set('worker:maintenance', (string) time());

        try {
            Queue::releaseStuck((int) Config::get('queue.stuck_minutes', 15));
            Queue::purge((int) Config::get('queue.keep_days', 7));

            (new RateLimiter())->cleanup();

            Cache::cleanup();
            RememberToken::purge();
            UserSession::purge((int) Config::get('auth.sessions_keep_days', 60));
            AuditEntry::purge((int) Config::get('audit.keep_days', 180));
            WebhookDelivery::purge((int) Config::get('webhooks.keep_days', 14));
            IncomingHook::purge((int) Config::get('webhooks.keep_days', 14));
            SecurityEvent::purge((int) Config::get('security.keep_days', 60));
            BlockedIp::purgeExpired();
            Trash::purge((int) Config::get('trash.keep_days', 30));
            ExportFile::purge((int) Config::get('export.keep_days', 7));
            Notify::purge();
            Backup::purgeTemp();

            (new Logger('app'))->purge();

            Scheduler::run();
        } catch (Throwable $e) {
            $this->logger->error('Обслуживание не доехало', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Ctrl+C и systemd stop должны останавливать воркер по-хорошему: доработать
     * текущую задачу и выйти, а не обрываться на середине.
     */
    private function listenSignals(): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }

        pcntl_async_signals(true);

        foreach ([SIGTERM, SIGINT] as $signal) {
            pcntl_signal($signal, function (): void {
                $this->stopping = true;

                $this->logger->info('Получен сигнал остановки, доработаю круг');
            });
        }
    }
}
