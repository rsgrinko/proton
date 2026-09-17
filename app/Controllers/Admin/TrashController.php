<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use Rsgrinko\Proton\Access\AccessDenied;
use Rsgrinko\Proton\Access\Permission;
use Rsgrinko\Proton\Access\Viewer;
use Rsgrinko\Proton\Database\Model\Model;
use Rsgrinko\Proton\Events\Events;
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
        $kinds = $this->visible($viewer);

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
            'active'    => 'trash',
            'kinds'     => $kinds,
            'counts'    => $this->counts($kinds),
            'kind'      => $key,
            'label'     => $kind['label'],
            'title'     => $kind['title'],
            // Смотреть раздел корзины и возвращать/добивать из него — разные права:
            // trash.view даёт только первое
            'canAct'    => $this->canAct($viewer, $kind),
            'page'      => $page ?? ['items' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'per_page' => 0],
        ], 'Корзина');
    }

    /**
     * Вернуть запись из корзины.
     */
    public function restore(Request $request, Viewer $viewer): Response
    {
        [$kind, $record] = $this->find($request, $viewer);

        $record->restore();

        $entity = Trash::kind($kind)['entity'] ?? $kind;

        Audit::action($entity, $record->id(), 'запись возвращена из корзины');

        Events::fire('trash.restored', ['kind' => $kind, 'entity' => $entity, 'record' => $record]);

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

        $id = $record->id();

        $record->forceDelete();

        $entity = Trash::kind($kind)['entity'] ?? $kind;

        Audit::deleted($entity, $id, 'запись удалена окончательно');

        Events::fire('trash.destroyed', ['kind' => $kind, 'entity' => $entity, 'record' => $record]);

        $this->flash('Запись удалена окончательно');

        return $this->redirect('admin.trash', ['kind' => $kind]);
    }

    /**
     * Вернуть или добить сразу несколько отмеченных записей.
     */
    public function bulk(Request $request, Viewer $viewer): Response
    {
        $data = $this->validate($request, [
            'action' => 'required|in:restore,destroy',
        ], ['action' => 'Действие']);

        $key   = (string) $request->input('kind', '');
        $kinds = $this->actionable($viewer);

        if (!isset($kinds[$key])) {
            throw new AccessDenied('Этот раздел корзины вам не доступен');
        }

        $ids = array_values(array_filter(array_map('intval', (array) $request->input('ids', []))));

        if ($ids === []) {
            $this->flash('Не отмечено ни одной записи', 'error');

            return $this->redirect('admin.trash', ['kind' => $key]);
        }

        /** @var class-string<Model> $model */
        $model  = $kinds[$key]['model'];
        $action = (string) $data['action'];
        $done   = 0;
        $entity = Trash::kind($key)['entity'] ?? $key;
        $event  = $action === 'restore' ? 'trash.restored' : 'trash.destroyed';

        /** @var Model $record */
        foreach ($model::query()->onlyTrashed()->whereIn('id', $ids)->get() as $record) {
            $action === 'restore' ? $record->restore() : $record->forceDelete();

            Events::fire($event, ['kind' => $key, 'entity' => $entity, 'record' => $record]);

            $done++;
        }

        if ($action === 'restore') {
            Audit::action($entity, 0, 'массовое возвращение из корзины: ' . $done);
        } else {
            Audit::deleted($entity, 0, 'массовое удаление из корзины: ' . $done);
        }

        $label = $action === 'restore' ? 'возвращено' : 'удалено окончательно';

        $this->flash($done > 0 ? 'Готово, ' . $label . ': ' . $done : 'Ничего не изменилось', $done > 0 ? 'ok' : 'error');

        return $this->redirect('admin.trash', ['kind' => $key]);
    }

    /**
     * Очистить раздел целиком, а не только то, что видно на странице.
     * Обратной дороги нет — кнопка на форме требует подтверждения.
     */
    public function clear(Request $request, Viewer $viewer): Response
    {
        $key   = (string) $request->input('kind', '');
        $kinds = $this->actionable($viewer);

        if (!isset($kinds[$key])) {
            throw new AccessDenied('Этот раздел корзины вам не доступен');
        }

        $removed = Trash::query($key)?->forceDelete() ?? 0;
        $entity  = Trash::kind($key)['entity'] ?? $key;

        Audit::deleted($entity, 0, 'корзина очищена целиком: ' . $removed);

        if ($removed > 0) {
            Events::fire('trash.cleared', ['kind' => $key, 'entity' => $entity, 'count' => $removed]);
        }

        $this->flash($removed > 0 ? 'Корзина очищена, удалено: ' . $removed : 'Корзина была пуста');

        return $this->redirect('admin.trash', ['kind' => $key]);
    }

    /**
     * Раздел и запись из запроса — с проверкой права на этот раздел.
     *
     * @return array{0: string, 1: Model}
     */
    private function find(Request $request, Viewer $viewer): array
    {
        $key    = (string) $request->input('kind', '');
        $kinds  = $this->actionable($viewer);

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
     * Разделы, которые этому человеку видно, — своё право раздела, а если
     * его нет, хватает и общего trash.view (или trash.manage — кто может
     * действовать, тот и видит).
     *
     * @return array<string, array{model: class-string<Model>, label: string, title: callable, permission: string}>
     */
    private function visible(Viewer $viewer): array
    {
        $visible = [];

        foreach (Trash::kinds() as $key => $kind) {
            if ($kind['permission'] === '' || $viewer->can($kind['permission']) || $viewer->canAny([Permission::TRASH_VIEW, Permission::TRASH_MANAGE])) {
                $visible[$key] = $kind;
            }
        }

        return $visible;
    }

    /**
     * Разделы, в которых этому человеку можно возвращать и добивать записи —
     * trash.view сюда не пускает, только своё право раздела или trash.manage.
     *
     * @return array<string, array{model: class-string<Model>, label: string, title: callable, permission: string}>
     */
    private function actionable(Viewer $viewer): array
    {
        $actionable = [];

        foreach (Trash::kinds() as $key => $kind) {
            if ($kind['permission'] === '' || $viewer->can($kind['permission']) || $viewer->can(Permission::TRASH_MANAGE)) {
                $actionable[$key] = $kind;
            }
        }

        return $actionable;
    }

    /**
     * @param array{model: class-string<Model>, label: string, title: callable, permission: string} $kind
     */
    private function canAct(Viewer $viewer, array $kind): bool
    {
        return $kind['permission'] === '' || $viewer->can($kind['permission']) || $viewer->can(Permission::TRASH_MANAGE);
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
