# Списки: фильтры, сортировка, выгрузка и загрузка

Раздел со списком состоит из трёх одинаковых кусков: фильтры разбирают адрес,
пагинация тащит их за собой, выгрузка отдаёт файлом ровно то, что на экране.
Всё это собирается из `Support\Filters`, а не пишется руками в каждом разделе.

## Фильтры

Контроллер описывает поля, вьюха рисует их партиалом `filters`:

```php
$filters = $this->filters($request, [
    Filter::search('q', 'Поиск', ['login', 'email', 'name'], 'логин, почта или имя'),
    Filter::select('role_id', 'Роль', $roles),
    Filter::flag('active', 'Активен'),
    Filter::dates('created', 'Заведён', 'created_at'),
])->sortable(['id', 'login', 'created_at'], 'id', 'asc');

return $this->view('admin/users', [
    'page'    => $filters->apply(User::query())->paginate($this->page($request), $this->perPage()),
    'filters' => $filters,
], 'Пользователи');
```

```php
<?= View::partial('filters', [
    'filters' => $filters,
    'route'   => 'admin.users',
    'total'   => $page['total'],
    'export'  => 'admin.users.export',
]) ?>
```

Заголовок колонки со стрелкой — партиал `sort`, ссылки страниц берут фильтры
из `$filters->params()`:

```php
<th><?= View::partial('sort', ['filters' => $filters, 'route' => 'admin.users', 'column' => 'login', 'label' => 'Логин']) ?></th>

<?= View::partial('pagination', [
    'route'  => 'admin.users',
    'page'   => $page['page'],
    'pages'  => $page['pages'],
    'params' => $filters->params(),
]) ?>
```

Типы полей: `search` (LIKE по нескольким колонкам через OR), `select` (значение
из списка), `text` (точное совпадение), `flag` (0/1), `filled` (колонка пуста
или нет — `read_at`, `deleted_at`), `dates` (диапазон; в адресе это два
параметра, `{ключ}_from` и `{ключ}_to`).

Что нужно помнить:

- **сортировать можно только по колонкам из белого списка** `sortable()`: имя
  уходит в `ORDER BY`, и брать его прямо из адреса нельзя;
- негодное значение из адреса не роняет страницу, а отбрасывается. Список — не
  форма, показывать ошибку тут некому, а ссылки правят руками постоянно;
- порядок, который нужен всегда (закреплённые сверху), ставится до фильтров:
  `$scope->apply(Note::query())->orderBy('pinned', 'desc')`, а `apply()` добавит
  сортировку человека следом.

## Выгрузка

Выгрузка идёт тем же запросом, что и страница, поэтому фильтр действует и в файле:

```php
public function export(Request $request): Response
{
    $filters = $this->listFilters($request);

    return $this->exportCsv($filters->apply(User::query()), [
        'login'  => 'Логин',
        'role'   => ['Роль', static fn (User $user): string => $roles[(int) $user->raw('role_id')] ?? ''],
        'active' => ['Активен', static fn (User $user): string => $user->isActive() ? 'да' : 'нет'],
    ], 'users', 'user', 'admin.users');
}
```

Колонка — «поле => подпись» или «поле => [подпись, как получить значение]».
Четвёртый аргумент пишет выгрузку в журнал действий: файл уносит данные наружу,
это действие, а не просмотр. Пятый — куда вернуть человека, если выгрузка
не состоялась.

Файл собирается в памяти, поэтому выборка больше `EXPORT_MAX_ROWS` (20000)
не отдаётся вовсе — человек получает просьбу сузить отбор. Строки достаются
порциями по 500, так что память ест сам файл, а не выборка.

CSV пишется с BOM и точкой с запятой (`EXPORT_DELIMITER`) — иначе Excel с русской
локалью показывает одну колонку и кракозябры. Ячейка, начинающаяся с `=`, `+`,
`-` или `@`, получает апостроф: файл может уехать кому угодно, и формула в нём
выполняться не должна.

## Загрузка

`Support\Import` разбирает файл построчно: колонки переводятся в поля, каждая
строка проходит `Validator`, и только потом её получает обработчик.

```php
$report = Import::csv($content, [
    'Название' => 'title',
    'Текст'    => 'body',
], [
    'title' => 'required|max:191',
    'body'  => 'nullable|max:20000',
], static function (array $row) use ($viewer): void {
    $note = new Note(['title' => (string) $row['title'], 'body' => (string) $row['body']]);

    $note->setAttribute('user_id', $viewer->id());
    $note->save();
});

View::stash('import', $report);
$this->flash($report->summary(), $report->failed > 0 ? 'error' : 'ok');
```

Одна плохая строка не отменяет файл: она попадает в отчёт с номером (как в Excel,
считая заголовок), остальные загружаются. Файла без нужных колонок это не касается —
он отвергается целиком, до первой строки.

Предел строк — `IMPORT_MAX_ROWS` (5000). Больше — либо делить файл, либо писать
свою задачу очереди: синхронный разбор упрётся в время ответа.

Образец целиком — раздел «Заметки»: форма на странице списка, отчёт об ошибках
над ней, колонки те же, что в выгрузке.
