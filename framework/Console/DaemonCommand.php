<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Console;

use Rsgrinko\Proton\Database\Lock;
use Rsgrinko\Proton\Support\Config;
use Rsgrinko\Proton\Support\Logger;
use Rsgrinko\Proton\Support\Settings;
use Throwable;

/**
 * Команда-демон: крутит tick() по кругу, пока её не остановят. Для того, что
 * не ложится в очередь: длинный опрос чужого API, слушатель сокета, разборщик
 * чужой очереди.
 *
 *     final class InboxPollCommand extends DaemonCommand
 *     {
 *         public function name(): string { return 'inbox:poll'; }
 *         public function description(): string { return 'Забирает входящие'; }
 *
 *         protected function tick(): void
 *         {
 *             // один круг: запрос с long-polling, разбор ответа
 *         }
 *     }
 *
 * Что берёт на себя:
 *
 * - один экземпляр на имя: второй запуск сразу выходит с кодом 1 (Database\Lock —
 *   GET_LOCK в MySQL, flock в SQLite);
 * - остановку по-хорошему — круг дорабатывается до конца: SIGTERM/SIGINT там,
 *   где есть pcntl (Linux, systemd stop), Ctrl+C/закрытие окна на Windows
 *   (sapi_windows_set_ctrl_handler), и на обеих — `php bin/proton daemon:stop <имя>`
 *   через файл-флаг в var/daemon: им же останавливают демон, запущенный в фоне
 *   на Windows, где сигнал ему не послать;
 * - перезапуск раз в lifetime() секунд: процесс выходит сам, systemd поднимает
 *   новый — так не копится память и подхватывается новый код;
 * - свежие настройки: перед каждым кругом Settings::refresh();
 * - упавший круг не роняет демон: ошибка уходит в лог, после паузы errorPause()
 *   следующий круг пробует снова.
 *
 * `--once` — один круг и выход: для cron и для проверки руками.
 */
abstract class DaemonCommand extends Command
{
    private bool $stopping = false;

    private ?Logger $logger = null;

    /**
     * Один круг работы.
     */
    abstract protected function tick(): void;

    /**
     * Пауза между кругами, сек. 0 — без паузы: так нужно длинному опросу, где
     * сам запрос и есть ожидание.
     */
    protected function interval(): int
    {
        return 1;
    }

    /**
     * Сколько секунд жить до выхода; 0 — без ограничения. Выходит демон между
     * кругами, а поднимает его заново systemd (Restart=always).
     */
    protected function lifetime(): int
    {
        return 3600;
    }

    /**
     * Пауза после упавшего круга, сек: чужой сервис лёг — долбить его каждую
     * секунду незачем.
     */
    protected function errorPause(): int
    {
        return 5;
    }

    /**
     * Попросить демон выйти после текущего круга — из tick(), например когда
     * без настройки работать дальше бессмысленно.
     */
    protected function stop(): void
    {
        $this->stopping = true;
    }

    /**
     * Пора ли выходить: долгому tick() есть смысл проверять это между шагами.
     */
    protected function stopping(): bool
    {
        return $this->stopping;
    }

    protected function logger(): Logger
    {
        return $this->logger ??= new Logger('daemon');
    }

    final public function run(): int
    {
        $name = $this->name();
        $lock = new Lock(null, 'daemon:' . $name);

        if (!$lock->acquire(0)) {
            $this->fail('Демон ' . $name . ' уже запущен');

            return 1;
        }

        $this->stopping = false;

        $once      = $this->hasOption('once');
        $startedAt = time();
        $stopMark  = self::stopMark($name);
        $ticks     = 0;

        $this->listenSignals();

        $this->logger()->info('Демон запущен', ['daemon' => $name, 'once' => $once]);

        try {
            do {
                Settings::refresh();

                $failed = false;

                try {
                    $this->tick();
                } catch (Throwable $e) {
                    $failed = true;

                    $this->logger()->error('Круг демона упал', ['daemon' => $name, 'error' => $e->getMessage()]);
                }

                $ticks++;

                if ($once || $this->shouldExit($name, $startedAt, $stopMark)) {
                    break;
                }

                $this->pause($failed ? max($this->interval(), $this->errorPause()) : $this->interval());
            } while (!$this->shouldExit($name, $startedAt, $stopMark));
        } finally {
            $this->restoreSignals();
            $lock->release();

            $this->logger()->info('Демон остановлен', ['daemon' => $name, 'ticks' => $ticks]);
        }

        return 0;
    }

    /**
     * Попросить демон с этим именем выйти — из другого процесса. Работает
     * одинаково на Linux и Windows: флаг в файле, а не сигнал.
     */
    public static function requestStop(string $name): void
    {
        $file = self::stopFile($name);

        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0775, true);
        }

        // Метка с долями секунды: демон, запущенный в ту же секунду после
        // предыдущей просьбы, не должен принять её на свой счёт
        file_put_contents($file, sprintf('%.6F', microtime(true)), LOCK_EX);
    }

    /**
     * Файл-флаг остановки. Имя чистится: оно приходит из командной строки.
     */
    public static function stopFile(string $name): string
    {
        $dir = (string) Config::get('paths.daemon', APP_ROOT . '/var/daemon');

        return $dir . '/' . (preg_replace('/[^A-Za-z0-9_.-]/', '_', $name) ?? 'daemon') . '.stop';
    }

    /**
     * Отметка в флаге на момент старта: старая просьба об остановке, на
     * которую уже вышел прошлый экземпляр, нового не касается.
     */
    private static function stopMark(string $name): float
    {
        $file = self::stopFile($name);

        clearstatcache(true, $file);

        return is_file($file) ? (float) @file_get_contents($file) : 0.0;
    }

    private function shouldExit(string $name, int $startedAt, float $stopMark): bool
    {
        if ($this->stopping) {
            return true;
        }

        if (self::stopMark($name) > $stopMark) {
            $this->logger()->info('Получена просьба об остановке', ['daemon' => $name]);

            return $this->stopping = true;
        }

        $lifetime = $this->lifetime();

        if ($lifetime > 0 && time() - $startedAt >= $lifetime) {
            $this->logger()->info('Демон отработал своё время и выходит', ['daemon' => $name]);

            return $this->stopping = true;
        }

        return false;
    }

    /**
     * Пауза кусками по 0,2 секунды: сигнал или флаг остановки не должны ждать
     * конца длинной паузы.
     */
    private function pause(int $seconds): void
    {
        $until = microtime(true) + max(0, $seconds);

        while (!$this->stopping && microtime(true) < $until) {
            usleep(200000);
        }
    }

    private function listenSignals(): void
    {
        if (function_exists('pcntl_signal')) {
            pcntl_async_signals(true);

            foreach ([SIGTERM, SIGINT] as $signal) {
                pcntl_signal($signal, function (): void {
                    $this->stopping = true;
                });
            }

            return;
        }

        // На Windows сигналов нет, есть Ctrl+C и Ctrl+Break в консоли. Функция
        // есть только в CLI, и обработчик там один на процесс
        if (PHP_OS_FAMILY === 'Windows' && function_exists('sapi_windows_set_ctrl_handler')) {
            sapi_windows_set_ctrl_handler(function (int $event): void {
                $this->stopping = true;
            });
        }
    }

    /**
     * Обработчики — обратно по умолчанию: демон мог работать внутри другого
     * процесса (тесты, tinker), и Ctrl+C там должен снова его останавливать,
     * а не взводить флаг у давно вышедшей команды.
     */
    private function restoreSignals(): void
    {
        if (function_exists('pcntl_signal')) {
            foreach ([SIGTERM, SIGINT] as $signal) {
                pcntl_signal($signal, SIG_DFL);
            }

            return;
        }

        if (PHP_OS_FAMILY === 'Windows' && function_exists('sapi_windows_set_ctrl_handler')) {
            sapi_windows_set_ctrl_handler(null);
        }
    }
}
