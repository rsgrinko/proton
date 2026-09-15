<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use Rsgrinko\Proton\Database\Model\RecordNotFound;
use Rsgrinko\Proton\Http\Controller;
use Rsgrinko\Proton\Http\Response;
use Rsgrinko\Proton\Support\ExportFile;

/**
 * Готовые файлы фоновых выгрузок.
 *
 * Вход не нужен: право скачать доказывает подпись ссылки из уведомления.
 * Сами файлы лежат вне public, поэтому отдаёт их код, а имя проверяется —
 * из адреса приходит что угодно.
 */
final class ExportsController extends Controller
{
    public function download(string $file): Response
    {
        $path = ExportFile::path($file);

        if ($path === null) {
            throw new RecordNotFound('Файл выгрузки не найден или уже удалён');
        }

        return Response::download((string) file_get_contents($path), $file, 'text/csv; charset=utf-8');
    }
}
