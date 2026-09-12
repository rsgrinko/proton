<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Console\Commands;

use Rsgrinko\Proton\Console\Command;
use Rsgrinko\Proton\Support\Config;

/**
 * поднять встроенный веб-сервер PHP.
 *
 * Ровно для разработки: сервер однопоточный, статику отдаёт сам и на бою
 * не используется. Порт можно не задавать — если он занят, команда возьмёт
 * следующий свободный и скажет об этом.
 */
final class ServeCommand extends Command
{
    public function name(): string
    {
        return 'serve';
    }

    public function description(): string
    {
        return 'запустить встроенный сервер для разработки';
    }

    public function usage(): string
    {
        return 'serve [--host=127.0.0.1] [--port=8000] [--tries=10]';
    }

    public function run(): int
    {
        $host  = (string) $this->option('host', (string) Config::get('app.serve_host', '127.0.0.1'));
        $port  = (int) $this->option('port', (string) Config::get('app.serve_port', '8000'));
        $tries = max(1, (int) $this->option('tries', '10'));

        $root = APP_ROOT . '/public';

        if (!is_file($root . '/index.php')) {
            $this->fail('Не найден public/index.php — сервер запускать нечего');

            return 1;
        }

        $free = $this->freePort($host, $port, $tries);

        if ($free === 0) {
            $this->fail('Свободный порт не нашёлся: занято всё от ' . $port . ' до ' . ($port + $tries - 1));

            return 1;
        }

        if ($free !== $port) {
            $this->line('Порт ' . $port . ' занят, беру ' . $free);
        }

        $this->line((string) Config::get('app.name', 'Proton') . ' слушает http://' . $host . ':' . $free);
        $this->line('Каталог: ' . $root);
        $this->line('Остановить — Ctrl+C');
        $this->line('');

        // Роутер отдаёт существующие файлы как есть, остальное уводит в index.php —
        // так встроенный сервер ведёт себя похоже на nginx с try_files
        $router = APP_ROOT . '/public/router.php';

        $command = escapeshellarg(PHP_BINARY)
            . ' -S ' . escapeshellarg($host . ':' . $free)
            . ' -t ' . escapeshellarg($root)
            . (is_file($router) ? ' ' . escapeshellarg($router) : '');

        // passthru, а не exec: вывод сервера должен идти человеку в терминал
        passthru($command, $code);

        return $code;
    }

    /**
     * Первый свободный порт начиная с указанного. 0 — не нашлось.
     */
    private function freePort(string $host, int $port, int $tries): int
    {
        for ($candidate = $port; $candidate < $port + $tries; $candidate++) {
            $socket = @stream_socket_server('tcp://' . $host . ':' . $candidate, $code, $error);

            if ($socket !== false) {
                fclose($socket);

                return $candidate;
            }
        }

        return 0;
    }
}
