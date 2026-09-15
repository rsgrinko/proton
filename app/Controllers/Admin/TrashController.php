<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use Rsgrinko\Proton\Access\AccessDenied;
use Rsgrinko\Proton\Access\Viewer;
use Rsgrinko\Proton\Database\Model\Model;
use Rsgrinko\Proton\Http\Controller;
use Rsgrinko\Proton\Http\Request;
use Rsgrinko\Proton\Http\Response;
use Rsgrinko\Proton\Support\Audit;
use Rsgrinko\Proton\Support\Trash;

/**
 * Корзина: удалённое, но ещё живое.
 *
 * Разделы объявляет реестр (`config/trash.php`), поэтому свой раздел появляется
 * здесь сам, без правки контроллера. Каждый закрыт своим правом: право видеть
 * пользователей и право видеть вебхуки — разные вещи и в корзине тоже.
 */
final class TrashController extends Controller
{
    public function index(Request $request, Viewer $viewer): Response
    {
        $kinds = $this->allowed($viewer);

        if ($kinds === []) {
            throw new AccessDenied('Корзина пуста для вашей роли');
        }

        $key  = (string) $request->query('kind', '');
        $key  = isset($kinds[$key]) ? $key : (string) array_key_first($kinds);
        $kind = $kinds[$key];

        $page = Trash::query($key)
            ?->orderBy(Model::DELETED_AT, 'desc')
            ->paginate($this->page($request), $this->perPage());

        return $this->view('admin/trash', [
            'active'  => 'trash',
            'kinds'   => $kinds,
            'counts'  => $this->counts($kinds),
            'kind'    => $key,
            'label'   => $kind['label'],
            'title'   => $kind['title'],
            'page'    => $page ?? ['items' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'per_page' => 0],
        ], 'Корзина');
    }

    /**
     * Вернуть запись из корзины.
     */
    public function restore(Request $request, Viewer $viewer): Response
    {
        [$kind, $record] = $this->find($request, $viewer);

        $record->restore();

        Audit::action(Trash::kind($kind)['entity'] ?? $kind, $record->id(), 'запись возвращена из корзины');

        $this->flash('Запись возвращена');

        return $this->redirect('admin.trash', ['kind' => $kind]);
    }

    /**
     * Добить окончательно. Обратной дороги нет, поэтому отдельное действие
     * и отдельная запись в журнале.
     */
    public function destroy(Request $request, Viewer $viewer): Response
    {
        [$kind, $record] = $this->find($request, $viewer);

        $record->forceDelete();

        Audit::deleted(Trash::kind($kind)['entity'] ?? $kind, $record->id(), 'запись удалена окончательно');

        $this->flash('Запись удалена окончательно');

        return $this->redirect('admin.trash', ['kind' => $kind]);
    }

    /**
     * Раздел и запись из запроса — с проверкой права на этот раздел.
     *
     * @return array{0: string, 1: Model}
     */
    private function find(Request $request, Viewer $viewer): array
    {
        $key    = (string) $request->input('kind', '');
        $kinds  = $this->allowed($viewer);

        if (!isset($kinds[$key])) {
            throw new AccessDenied('Этот раздел корзины вам не доступен');
        }

        /** @var class-string<Model> $model */
        $model = $kinds[$key]['model'];

        /** @var Model|null $record */
        $record = $model::query()->onlyTrashed()->where('id', (int) $request->input('id', 0))->first();

        /** @var Model $found */
        $found = $this->require($record, 'admin.trash', 'Записи в корзине нет');

        return [$key, $found];
    }

    /**
     * Разделы, доступные этому человеку.
     *
     * @return array<string, array{model: class-string<Model>, label: string, title: callable, permission: string}>
     */
    private function allowed(Viewer $viewer): array
    {
        $allowed = [];

        foreach (Trash::kinds() as $key => $kind) {
            if ($kind['permission'] === '' || $viewer->can($kind['permission'])) {
                $allowed[$key] = $kind;
            }
        }

        return $allowed;
    }

    /**
     * @param array<string, array<string, mixed>> $kinds
     *
     * @return array<string, int>
     */
    private function counts(array $kinds): array
    {
        $counts = [];

        foreach (array_keys($kinds) as $key) {
            $counts[$key] = Trash::query($key)?->count() ?? 0;
        }

        return $counts;
    }
}
