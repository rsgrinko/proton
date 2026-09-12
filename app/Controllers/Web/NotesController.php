<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Models\Note;
use Rsgrinko\Proton\Access\Scope;
use Rsgrinko\Proton\Access\Viewer;
use Rsgrinko\Proton\Files\Storage;
use Rsgrinko\Proton\Http\Controller;
use Rsgrinko\Proton\Http\Request;
use Rsgrinko\Proton\Http\Response;
use Rsgrinko\Proton\Support\Audit;
use Rsgrinko\Proton\Support\ProtonException;

/**
 * Заметки — демонстрационный раздел: список с поиском и страницами, карточка,
 * форма, загрузка файла, мягкое удаление.
 *
 * Здесь же видно, как работает область видимости: обычный пользователь видит
 * только свои заметки, а для администратора (право data.all) видны все.
 * Чужая запись для остальных просто не существует — «не найдено», а не
 * «нет доступа»: постороннему знать нечего.
 */
final class NotesController extends Controller
{
    public function index(Request $request, Scope $scope): Response
    {
        $search = $request->text('q');

        $query = $scope->apply(Note::query())
            ->when($search, static function ($query, string $needle): void {
                $query->whereLike('title', $needle);
            })
            ->orderBy('pinned', 'desc')
            ->orderBy('id', 'desc');

        $page = $query->paginate($this->page($request), $this->perPage());

        return $this->view('notes/index', [
            'active' => 'notes',
            'page'   => $page,
            'search' => $search,
        ], 'Заметки');
    }

    public function create(): Response
    {
        return $this->view('notes/form', [
            'active' => 'notes',
            'note'   => new Note(),
        ], 'Новая заметка');
    }

    public function store(Request $request, Viewer $viewer): Response
    {
        $data = $this->validate($request, [
            'title'  => 'required|max:191',
            'body'   => 'nullable|max:20000',
            'pinned' => 'nullable|boolean',
        ], ['title' => 'Название', 'body' => 'Текст']);

        $note = new Note($data);

        $note->setAttribute('user_id', $viewer->id());

        $this->attachFile($request, $note);

        $note->save();

        Audit::created('note', $note->id(), 'заметка «' . (string) $note->title . '»');

        $this->flash('Заметка сохранена');

        return $this->redirect('notes.show', ['id' => $note->id()]);
    }

    public function show(int $id, Scope $scope): Response
    {
        $note = $this->find($id, $scope);

        return $this->view('notes/show', [
            'active' => 'notes',
            'note'   => $note,
            'author' => $note->author,
        ], (string) $note->title);
    }

    public function edit(int $id, Scope $scope): Response
    {
        return $this->view('notes/form', [
            'active' => 'notes',
            'note'   => $this->find($id, $scope),
        ], 'Правка заметки');
    }

    public function update(Request $request, int $id, Scope $scope): Response
    {
        $note = $this->find($id, $scope);

        $data = $this->validate($request, [
            'title'  => 'required|max:191',
            'body'   => 'nullable|max:20000',
            'pinned' => 'nullable|boolean',
        ], ['title' => 'Название', 'body' => 'Текст']);

        $before = ['title' => $note->title, 'body' => $note->body, 'pinned' => $note->raw('pinned')];

        $note->fill($data);

        $this->attachFile($request, $note);

        $note->save();

        Audit::updated('note', $note->id(), 'заметка «' . (string) $note->title . '»', Audit::between($before, [
            'title'  => $note->title,
            'body'   => $note->body,
            'pinned' => $note->raw('pinned'),
        ]));

        $this->flash('Заметка сохранена');

        return $this->redirect('notes.show', ['id' => $note->id()]);
    }

    public function delete(int $id, Scope $scope): Response
    {
        $note = $this->find($id, $scope);

        // Мягкое удаление: запись остаётся в базе с отметкой времени
        $note->delete();

        Audit::deleted('note', $note->id(), 'заметка «' . (string) $note->title . '»');

        $this->flash('Заметка удалена');

        return $this->redirect('notes.index');
    }

    /**
     * Отдаёт приложенный файл. Хранилище лежит вне public, поэтому файлы
     * отдаёт код — присланный «аватар.php» не должен стать скриптом на сайте.
     */
    public function file(int $id, Scope $scope): Response
    {
        $note = $this->find($id, $scope);

        if (!$note->hasFile() || !Storage::exists((string) $note->raw('file_path'))) {
            $this->flash('Файла нет', 'error');

            return $this->redirect('notes.show', ['id' => $note->id()]);
        }

        return Response::download(
            Storage::read((string) $note->raw('file_path')),
            (string) $note->raw('file_name')
        );
    }

    /**
     * Заметка с оглядкой на область видимости: чужая для обычного пользователя
     * просто не существует.
     */
    private function find(int $id, Scope $scope): Note
    {
        /** @var Note|null $note */
        $note = $scope->apply(Note::query())->where('id', $id)->first();

        /** @var Note $found */
        $found = $this->require($note, 'notes.index', 'Заметка не найдена');

        return $found;
    }

    /**
     * Прикладывает файл, если его прислали. Прежний удаляем после того, как
     * новый лёг на диск, — иначе при ошибке останемся без обоих.
     */
    private function attachFile(Request $request, Note $note): void
    {
        $file = $request->file('attachment');

        if ($file === null || !$file->uploaded()) {
            return;
        }

        try {
            $stored = Storage::put($file, 'notes');
        } catch (ProtonException $e) {
            $this->flash($e->getMessage(), 'error');

            return;
        }

        $previous = (string) $note->raw('file_path');

        $note->forceFill(['file_path' => $stored['path'], 'file_name' => $stored['name']]);

        if ($previous !== '' && $previous !== $stored['path']) {
            Storage::delete($previous);
        }
    }
}
