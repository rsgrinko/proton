<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use Rsgrinko\Proton\Http\Controller;
use Rsgrinko\Proton\Http\Request;
use Rsgrinko\Proton\Http\Response;
use Rsgrinko\Proton\Support\Config;
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

        $level  = $request->text('level');
        $needle = $request->text('q');
        $lines  = [];

        // Хвост читаем глубже, а показываем страницами: под фильтр может подойти
        // и тысяча строк, вываливать их одним куском незачем
        $depth = max(500, (int) Config::get('log.tail_lines', 2000));

        if ($current !== '') {
            foreach ($logger->tail($current, $depth) as $line) {
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

        $perPage = $this->perPage();
        $pages   = max(1, (int) ceil(count($lines) / $perPage));
        $page    = min($this->page($request), $pages);

        return $this->view('admin/logs', [
            'active'  => 'logs',
            'files'   => $files,
            'current' => $current,
            'lines'   => array_slice($lines, ($page - 1) * $perPage, $perPage),
            'total'   => count($lines),
            'page'    => $page,
            'pages'   => $pages,
            'depth'   => $depth,
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
