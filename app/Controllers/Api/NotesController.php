<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\Note;
use Rsgrinko\Proton\Access\Scope;
use Rsgrinko\Proton\Access\Viewer;
use Rsgrinko\Proton\Http\Request;
use Rsgrinko\Proton\Http\Response;
use Rsgrinko\Proton\Support\Audit;

/**
 * Заметки через API — тот же раздел, что и в вебе, только для машин.
 *
 * Область видимости здесь та же: ключ принадлежит человеку, и через него
 * доступны ровно его записи. Чужая заметка не существует — 404, а не 403.
 */
final class NotesController extends ApiController
{
    public function index(Request $request, Scope $scope): Response
    {
        $query = $scope->apply(Note::query())
            ->when($request->text('q'), static fn ($query, string $needle) => $query->whereLike('title', $needle))
            ->orderBy('id', 'desc');

        return $this->paginated(
            $query->paginate($this->page($request), $this->perPage()),
            static fn (Note $note): array => $note->toArray()
        );
    }

    public function show(int $id, Scope $scope): Response
    {
        /** @var Note|null $note */
        $note = $scope->apply(Note::query())->where('id', $id)->first();

        if ($note === null) {
            return Response::error('Заметка не найдена', 404);
        }

        return $this->data($note->toArray());
    }

    public function store(Request $request, Viewer $viewer): Response
    {
        $data = $this->payload($request, [
            'title'  => 'required|max:191',
            'body'   => 'nullable|max:20000',
            'pinned' => 'nullable|boolean',
        ]);

        $note = new Note($data);

        $note->setAttribute('user_id', $viewer->id());
        $note->save();

        Audit::created('note', $note->id(), 'заметка через API: ' . (string) $note->title);

        return $this->data($note->toArray(), 201);
    }

    public function update(Request $request, int $id, Scope $scope): Response
    {
        /** @var Note|null $note */
        $note = $scope->apply(Note::query())->where('id', $id)->first();

        if ($note === null) {
            return Response::error('Заметка не найдена', 404);
        }

        $data = $this->payload($request, [
            'title'  => 'nullable|max:191',
            'body'   => 'nullable|max:20000',
            'pinned' => 'nullable|boolean',
        ]);

        // Пустые поля не затираем: PATCH меняет то, что прислали
        $note->fill(array_filter($data, static fn (mixed $value): bool => $value !== null));
        $note->save();

        Audit::updated('note', $note->id(), 'заметка через API: ' . (string) $note->title);

        return $this->data($note->toArray());
    }

    public function delete(int $id, Scope $scope): Response
    {
        /** @var Note|null $note */
        $note = $scope->apply(Note::query())->where('id', $id)->first();

        if ($note === null) {
            return Response::error('Заметка не найдена', 404);
        }

        $note->delete();

        Audit::deleted('note', $id, 'заметка через API');

        return $this->data(['deleted' => true]);
    }
}
