<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use Rsgrinko\Proton\Backup\Backup;
use Rsgrinko\Proton\Backup\Ftp;
use Rsgrinko\Proton\Backup\ShipBackupJob;
use Rsgrinko\Proton\Http\Controller;
use Rsgrinko\Proton\Http\Request;
use Rsgrinko\Proton\Http\Response;
use Rsgrinko\Proton\Queue\Queue;
use Rsgrinko\Proton\Support\Audit;
use Rsgrinko\Proton\Support\Config;
use Rsgrinko\Proton\Support\ProtonException;

/**
 * Резервные копии базы: сделать, скачать, проверить, удалить.
 *
 * Восстановления здесь нет нарочно. Замена всех данных — не то действие, которое
 * делают кнопкой в браузере между делом: для него есть консоль с обязательным
 * `--force`, и там видно, что именно произойдёт.
 */
final class BackupsController extends Controller
{
    public function index(Request $request): Response
    {
        // Копий бывает много: ручные накапливаются рядом с суточными
        $files   = Backup::files();
        $perPage = $this->perPage();
        $pages   = max(1, (int) ceil(count($files) / $perPage));
        $page    = min($this->page($request), $pages);

        return $this->view('admin/backups', [
            'active'   => 'backups',
            'files'    => array_slice($files, ($page - 1) * $perPage, $perPage),
            'total'    => count($files),
            'page'     => $page,
            'pages'    => $pages,
            'keep'     => (int) Config::get('backup.keep', 7),
            'schedule' => trim((string) Config::get('backup.schedule', '')),
            'dir'      => Backup::directory(),
            'ftp'      => Ftp::enabled(),
        ], 'Резервные копии');
    }

    public function store(): Response
    {
        try {
            $info = Backup::create();
        } catch (ProtonException $e) {
            $this->flash('Копия не сделалась: ' . $e->getMessage(), 'error');

            return $this->redirect('admin.backups');
        }

        $removed = Backup::rotate();

        Audit::created('backup', $info['name'], 'сделана копия базы, ' . Backup::size($info['size']));

        $this->flash(
            'Копия готова и проверена: ' . $info['name'] . ', ' . Backup::size($info['size'])
            . ($removed > 0 ? '. Старых удалено: ' . $removed : '')
        );

        return $this->redirect('admin.backups');
    }

    /**
     * Отдаёт файл копии. Каталог лежит вне public, поэтому файл отдаёт код —
     * копия базы не должна быть доступна по прямой ссылке.
     */
    public function download(Request $request): Response
    {
        $name = $this->name($request);

        if ($name === '') {
            return $this->redirect('admin.backups');
        }

        $check = Backup::verify($name);

        if (!$check['ok']) {
            $this->flash('Эта копия негодна (' . $check['message'] . '), качать нечего', 'error');

            return $this->redirect('admin.backups');
        }

        Audit::action('backup', $name, 'скачана копия базы');

        return Response::download(Backup::read($name), $name);
    }

    public function check(Request $request): Response
    {
        $name = $this->name($request);

        if ($name === '') {
            return $this->redirect('admin.backups');
        }

        $result = Backup::verify($name);

        $this->flash(
            ($result['ok'] ? 'Копия годна — ' : 'Копия негодна: ') . $result['message'],
            $result['ok'] ? 'ok' : 'error'
        );

        return $this->redirect('admin.backups');
    }

    /**
     * Ставит отправку на FTP в очередь — так же, как обычная копия, эта не
     * должна держать страницу, пока идёт по сети до чужого сервера.
     */
    public function ship(Request $request): Response
    {
        $name = $this->name($request);

        if ($name === '') {
            return $this->redirect('admin.backups');
        }

        if (!Ftp::enabled()) {
            $this->flash('FTP не настроен: задайте FTP_HOST в .env', 'error');

            return $this->redirect('admin.backups');
        }

        Queue::push(ShipBackupJob::class, ['name' => $name]);

        Audit::action('backup', $name, 'копия поставлена в очередь на отправку по FTP');

        $this->flash('Отправка поставлена в очередь — заберёт воркер');

        return $this->redirect('admin.backups');
    }

    public function delete(Request $request): Response
    {
        $name = $this->name($request);

        if ($name === '') {
            return $this->redirect('admin.backups');
        }

        if (!Backup::delete($name)) {
            $this->flash('Файл не найден', 'error');

            return $this->redirect('admin.backups');
        }

        Audit::deleted('backup', $name, 'удалена копия базы');

        $this->flash('Копия удалена');

        return $this->redirect('admin.backups');
    }

    /**
     * Имя файла из запроса. Проверяет его сам Backup: «..» в имени означало бы
     * чтение и удаление чужих файлов, и такой запрос до действия не доходит.
     * Пустая строка в ответ — значит ругаться уже не на что.
     */
    private function name(Request $request): string
    {
        $name = trim((string) $request->input('name', ''));

        try {
            Backup::resolve($name);
        } catch (ProtonException $e) {
            $this->flash($e->getMessage(), 'error');

            return '';
        }

        return $name;
    }
}
