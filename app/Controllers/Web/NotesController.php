<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Contracts\NoteLimiter;
use App\Models\Note;
use Rsgrinko\Proton\Access\Policy;
use Rsgrinko\Proton\Access\Scope;
use Rsgrinko\Proton\Access\Viewer;
use Rsgrinko\Proton\Comments\Comment;
use Rsgrinko\Proton\Database\Model\Query;
use Rsgrinko\Proton\Database\Model\RecordNotFound;
use Rsgrinko\Proton\Events\Events;
use Rsgrinko\Proton\Files\Attachment;
use Rsgrinko\Proton\Files\Storage;
use Rsgrinko\Proton\Http\Controller;
use Rsgrinko\Proton\Http\Request;
use Rsgrinko\Proton\Http\Response;
use Rsgrinko\Proton\Support\Audit;
use Rsgrinko\Proton\Support\Config;
use Rsgrinko\Proton\Support\Filter;
use Rsgrinko\Proton\Support\Filters;
use Rsgrinko\Proton\Support\Import;
use Rsgrinko\Proton\Support\ProtonException;
use Rsgrinko\Proton\View\View;

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
    /** Колонки файла: те же, что в выгрузке */
    private const COLUMNS = ['Название' => 'title', 'Текст' => 'body', 'Закреплена' => 'pinned'];

    /** Проверка строки файла */
    private const RULES = ['title' => 'required|max:191', 'body' => 'nullable|max:20000', 'pinned' => 'nullable|max:10'];

    public function index(Request $request, Scope $scope): Response
    {
        $filters = $this->listFilters($request);

        return $this->view('notes/index', [
            'active'  => 'notes',
            'page'    => $filters->apply($this->listQuery($scope))->paginate($this->page($request), $this->perPage()),
            'filters' => $filters,
        ], 'Заметки');
    }

    /**
     * Выгрузка: своя область видимости остаётся в силе — чужие заметки
     * не уедут в файл.
     */
    public function export(Request $request, Scope $scope): Response
    {
        $filters = $this->listFilters($request);

        return $this->exportCsv($filters->apply($this->listQuery($scope)), [
            'id'         => 'ID',
            'title'      => 'Название',
            'body'       => 'Текст',
            'pinned'     => ['Закреплена', static fn (Note $note): string => $note->pinned ? 'да' : 'нет'],
            'created_at' => 'Создана',
            'updated_at' => 'Изменена',
        ], 'notes', 'note', 'notes.index', $request);
    }

    private function listFilters(Request $request): Filters
    {
        return $this->filters($request, [
            Filter::search('q', 'Поиск', ['title', 'body'], 'заголовок или текст'),
            Filter::flag('pinned', 'Закреплена'),
            Filter::dates('created', 'Создана', 'created_at'),
        ])->sortable(['id', 'title', 'created_at'], 'id');
    }

    /**
     * Закреплённые всегда сверху, поэтому их порядок ставится до фильтров.
     */
    private function listQuery(Scope $scope): Query
    {
        return $scope->apply(Note::query())->orderBy('pinned', 'desc');
    }

    /**
     * Загрузка заметок из CSV. Файл разбирается построчно: плохая строка
     * попадает в отчёт, остальные заводятся.
     */
    public function import(Request $request, Viewer $viewer): Response
    {
        $content = $this->uploaded($request);

        if ($content === null) {
            return $this->redirect('notes.index');
        }

        // Первый шаг — предпросмотр: человек видит, что заведётся, что обновится
        // и что отвалится, и только потом решает
        $plan = Import::plan(
            $content,
            self::COLUMNS,
            self::RULES,
            [],
            fn (array $row): ?Note => $this->locate($row, $viewer)
        );

        View::stash('import_plan', $plan);
        View::stash('import_file', $this->keepFile($content));

        $this->flash(
            $plan->any() ? 'Проверьте, что получится, и подтвердите загрузку' : 'Загружать нечего',
            $plan->any() ? 'ok' : 'error'
        );

        return $this->redirect('notes.index');
    }

    /**
     * Второй шаг: подтверждённая загрузка. Файл берётся тот же, что смотрели
     * в предпросмотре, — заново его не присылают.
     */
    public function importConfirm(Request $request, Viewer $viewer): Response
    {
        $content = $this->takeFile((string) $request->input('file', ''));

        if ($content === null) {
            $this->flash('Файл потерялся — загрузите его заново', 'error');

            return $this->redirect('notes.index');
        }

        $report = Import::csv(
            $content,
            self::COLUMNS,
            self::RULES,
            function (array $row) use ($viewer): void {
                $note = new Note($this->fields($row));

                $note->setAttribute('user_id', $viewer->id());
                $note->save();
            },
            [],
            fn (array $row): ?Note => $this->locate($row, $viewer),
            function (Note $note, array $row): void {
                // Повторная загрузка того же файла правит запись, а не двоит её
                $note->fill($this->fields($row));
                $note->save();
            }
        );

        Audit::action('note', 0, 'импорт заметок: ' . $report->summary());

        View::stash('import', $report);

        $this->flash($report->summary(), $report->failed > 0 ? 'error' : 'ok');

        return $this->redirect('notes.index');
    }

    /**
     * Содержимое присланного файла или null, если прислали не то.
     */
    private function uploaded(Request $request): ?string
    {
        $file = $request->file('file');

        if ($file === null || !$file->uploaded()) {
            $this->flash($file?->error() ?? 'Выберите файл CSV', 'error');

            return null;
        }

        if ($file->extension() !== 'csv') {
            $this->flash('Нужен файл CSV', 'error');

            return null;
        }

        return (string) file_get_contents($file->tmpPath());
    }

    /**
     * Кладёт файл между шагами во временный каталог и возвращает метку.
     * В сессии его держать нельзя: файл бывает на мегабайты.
     */
    private function keepFile(string $content): string
    {
        $token = bin2hex(random_bytes(8));

        file_put_contents($this->tempPath($token), $content);

        return $token;
    }

    /**
     * Забирает отложенный файл и сразу убирает его: второй раз он не нужен.
     */
    private function takeFile(string $token): ?string
    {
        if (preg_match('/^[a-f0-9]{16}$/', $token) !== 1) {
            return null;
        }

        $path = $this->tempPath($token);

        if (!is_file($path)) {
            return null;
        }

        $content = (string) file_get_contents($path);

        @unlink($path);

        return $content;
    }

    private function tempPath(string $token): string
    {
        $dir = (string) Config::get('paths.tmp', APP_ROOT . '/var/tmp') . '/import';

        @mkdir($dir, 0775, true);

        return $dir . '/' . $token . '.csv';
    }

    /**
     * Уже есть такая заметка? Ключ — название в пределах своих записей.
     *
     * @param array<string, mixed> $row
     */
    private function locate(array $row, Viewer $viewer): ?Note
    {
        /** @var Note|null $note */
        $note = Note::query()
            ->where('user_id', $viewer->id())
            ->where('title', (string) $row['title'])
            ->first();

        return $note;
    }

    /**
     * Поля заметки из строки файла.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function fields(array $row): array
    {
        return [
            'title'  => (string) $row['title'],
            'body'   => (string) ($row['body'] ?? ''),
            'pinned' => in_array(mb_strtolower((string) ($row['pinned'] ?? '')), ['да', '1', 'true'], true) ? 1 : 0,
        ];
    }

    public function create(): Response
    {
        return $this->view('notes/form', [
            'active' => 'notes',
            'note'   => new Note(),
        ], 'Новая заметка');
    }

    public function store(Request $request, Viewer $viewer, NoteLimiter $limiter): Response
    {
        $data = $this->validate($request, [
            'title'  => 'required|max:191',
            'body'   => 'nullable|max:20000',
            'pinned' => 'nullable|boolean',
        ], ['title' => 'Название', 'body' => 'Текст']);

        // Проверка лимита подставляется контейнером (config/services.php) —
        // ограничение правится из панели, 0 в notes.per_user снимает его
        if ($limiter->exceeded($viewer->id())) {
            $this->flash('Заметок уже столько, что настройка не разрешает завести ещё', 'error');

            return $this->redirect('notes.index');
        }

        $note = new Note($data);

        $note->setAttribute('user_id', $viewer->id());

        $note->save();

        // Вложения прикладываются после сохранения: до него у записи нет id
        $this->attachFile($request, $note);

        Audit::created('note', $note->id(), 'заметка «' . (string) $note->title . '»');

        // Событие уходит подписчикам вебхуков — список в config/webhooks.php
        Events::fire('note.created', ['note' => $note]);

        $this->flash('Заметка сохранена');

        return $this->redirect('notes.show', ['id' => $note->id()]);
    }

    public function show(int $id, Scope $scope): Response
    {
        $note = $this->find($id, $scope);

        return $this->view('notes/show', [
            'active'      => 'notes',
            'note'        => $note,
            'author'      => $note->author,
            'attachments' => Attachment::of('note', $note->id()),
            'comments'    => Comment::of('note', $note->id()),
        ], (string) $note->title);
    }

    public function edit(int $id, Scope $scope): Response
    {
        $note = $this->find($id, $scope);

        $this->authorize('note.edit', $note);

        return $this->view('notes/form', [
            'active' => 'notes',
            'note'   => $note,
        ], 'Правка заметки');
    }

    public function update(Request $request, int $id, Scope $scope): Response
    {
        $note = $this->find($id, $scope);

        $this->authorize('note.edit', $note);

        $data = $this->validate($request, [
            'title'  => 'required|max:191',
            'body'   => 'nullable|max:20000',
            'pinned' => 'nullable|boolean',
        ], ['title' => 'Название', 'body' => 'Текст']);

        $before = ['title' => $note->title, 'body' => $note->body, 'pinned' => $note->raw('pinned')];

        // Закрепление — отдельное правило: у кого его нет, у того в форме нет
        // и галочки, и молчаливо открепить заметку такая форма не должна
        if (Policy::denies('note.pin', $note)) {
            unset($data['pinned']);
        }

        $note->fill($data);

        $this->attachFile($request, $note);

        $note->save();

        Audit::updated('note', $note->id(), 'заметка «' . (string) $note->title . '»', Audit::between($before, [
            'title'  => $note->title,
            'body'   => $note->body,
            'pinned' => $note->raw('pinned'),
        ]));

        Events::fire('note.updated', ['note' => $note]);

        $this->flash('Заметка сохранена');

        return $this->redirect('notes.show', ['id' => $note->id()]);
    }

    public function delete(int $id, Scope $scope): Response
    {
        $note = $this->find($id, $scope);

        $this->authorize('note.delete', $note);

        // Мягкое удаление: запись остаётся в базе с отметкой времени, а файлы
        // вложений живут дальше — вернём заметку из корзины, вернутся и они
        $note->delete();

        Audit::deleted('note', $note->id(), 'заметка «' . (string) $note->title . '»');

        Events::fire('note.deleted', ['note' => $note]);

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
     * Тот же файл, но по подписанной ссылке: вход не нужен, потому что право
     * на скачивание доказывает сама подпись. Ссылку выдаёт карточка заметки,
     * срок жизни — сутки.
     */
    public function sharedFile(int $id): Response
    {
        /** @var Note|null $note */
        $note = Note::query()->where('id', $id)->first();

        if ($note === null || !$note->hasFile() || !Storage::exists((string) $note->raw('file_path'))) {
            throw new RecordNotFound('Файла нет');
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
        $files = $request->files('attachment');

        if ($files === [] || $note->id() === 0) {
            return;
        }

        foreach ($files as $file) {
            try {
                Attachment::attach($file, 'note', $note->id(), (int) $note->raw('user_id'));
            } catch (ProtonException $e) {
                // Один негодный файл не должен отменять остальные и саму правку
                $this->flash($file->name() . ': ' . $e->getMessage(), 'error');
            }
        }
    }

    /**
     * Отдаёт вложение. Хранилище лежит вне public, поэтому файлы отдаёт код:
     * присланный «аватар.php» не должен стать скриптом на сайте.
     */
    public function attachment(int $id, int $attachment, Scope $scope): Response
    {
        $note = $this->find($id, $scope);

        /** @var Attachment|null $found */
        $found = Attachment::query()
            ->where('entity', 'note')
            ->where('entity_id', (string) $note->id())
            ->where('id', $attachment)
            ->first();

        if ($found === null || !Storage::exists((string) $found->raw('path'))) {
            $this->flash('Файла нет', 'error');

            return $this->redirect('notes.show', ['id' => $note->id()]);
        }

        return Response::download(
            Storage::read((string) $found->raw('path')),
            (string) $found->raw('name'),
            (string) $found->raw('mime') ?: 'application/octet-stream'
        );
    }

    /**
     * Оставить комментарий. Доступно всем, кто видит заметку, — обсуждение,
     * а не правка: право смотреть уже проверено прослойкой notes.view.
     */
    public function comment(Request $request, int $id, Scope $scope, Viewer $viewer): Response
    {
        $note = $this->find($id, $scope);

        $data = $this->validate($request, [
            'body' => 'required|max:2000',
        ], ['body' => 'Комментарий']);

        Comment::add('note', $note->id(), $viewer->id(), (string) $data['body']);

        Audit::action('note', $note->id(), 'оставлен комментарий');

        $this->flash('Комментарий добавлен');

        return $this->redirect('notes.show', ['id' => $note->id()]);
    }

    /**
     * Убрать комментарий: свой — сам автор, чужой — только у кого есть
     * notes.manage. Одно и то же действие, а не два разных маршрута.
     */
    public function commentDelete(Request $request, int $id, Scope $scope, Viewer $viewer): Response
    {
        $note = $this->find($id, $scope);

        /** @var Comment|null $comment */
        $comment = Comment::query()
            ->where('entity', 'note')
            ->where('entity_id', (string) $note->id())
            ->where('id', (int) $request->input('comment', 0))
            ->first();

        if ($comment === null) {
            $this->flash('Комментария нет', 'error');

            return $this->redirect('notes.show', ['id' => $note->id()]);
        }

        if ((int) $comment->raw('user_id') !== $viewer->id() && !$viewer->can('notes.manage')) {
            $this->flash('Убрать можно только свой комментарий', 'error');

            return $this->redirect('notes.show', ['id' => $note->id()]);
        }

        $comment->delete();

        Audit::action('note', $note->id(), 'убран комментарий');

        $this->flash('Комментарий убран');

        return $this->redirect('notes.show', ['id' => $note->id()]);
    }

    /**
     * Убрать вложение заметки.
     */
    public function detach(Request $request, int $id, Scope $scope): Response
    {
        $note = $this->find($id, $scope);

        $this->authorize('note.edit', $note);

        /** @var Attachment|null $attachment */
        $attachment = Attachment::query()
            ->where('entity', 'note')
            ->where('entity_id', (string) $note->id())
            ->where('id', (int) $request->input('attachment', 0))
            ->first();

        if ($attachment === null) {
            $this->flash('Вложения нет', 'error');

            return $this->redirect('notes.show', ['id' => $note->id()]);
        }

        $name = (string) $attachment->raw('name');

        $attachment->remove();

        Audit::action('note', $note->id(), 'убрано вложение «' . $name . '»');

        $this->flash('Вложение убрано');

        return $this->redirect('notes.show', ['id' => $note->id()]);
    }
}
