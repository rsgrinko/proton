<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use Rsgrinko\Proton\Http\Controller;
use Rsgrinko\Proton\Http\Request;
use Rsgrinko\Proton\Http\Response;
use Rsgrinko\Proton\Support\Logger;

/**
 * Логи: список файлов и таблица последних строк.
 *
 * Файл читается с конца порциями — лог на десятки мегабайт в память
 * не поднимается.
 */
final class LogsController extends Controller
{
    public function index(Request $request): Response
    {
        $logger = new Logger();
        $files  = $logger->files();

        $current = basename($request->text('file'));

        if ($current === '' || !$this->known($files, $current)) {
            $current = $files[0]['name'] ?? '';
        }

        $level = $request->text('level');
        $needle = $request->text('q');
        $lines  = [];

        if ($current !== '') {
            foreach ($logger->tail($current, 500) as $line) {
                $parsed = Logger::parse($line);

                if ($level !== '' && $parsed['level'] !== $level) {
                    continue;
                }

                if ($needle !== '' && mb_stripos($line, $needle) === false) {
                    continue;
                }

                $lines[] = $parsed;
            }

            // Свежие сверху: последняя строка файла — самая интересная
            $lines = array_reverse($lines);
        }

        return $this->view('admin/logs', [
            'active'  => 'logs',
            'files'   => $files,
            'current' => $current,
            'lines'   => $lines,
            'filters' => ['level' => $level, 'q' => $needle],
        ], 'Логи');
    }

    /**
     * @param array<int, array{name: string, path: string, size: int, mtime: int}> $files
     */
    private function known(array $files, string $name): bool
    {
        foreach ($files as $file) {
            if ($file['name'] === $name) {
                return true;
            }
        }

        return false;
    }
}
