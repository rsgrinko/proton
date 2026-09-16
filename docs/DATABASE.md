# База, построитель запросов и модели

## Подключение

`Rsgrinko\Proton\Database\Connection` — тонкая обёртка над PDO. Знает два драйвера,
`sqlite` и `mysql`, и прячет различия между ними: автоинкремент, upsert, блокировки
строк, полнотекстовые индексы.

```php
$db = Connection::instance();

$db->select('SELECT * FROM users WHERE active = :active', ['active' => 1]);
$db->selectOne('SELECT * FROM users WHERE id = :id', ['id' => 5]);
$db->value('SELECT COUNT(*) FROM users');
$db->execute('UPDATE users SET active = 0 WHERE id = :id', ['id' => 5]);

$id = $db->insert('notes', ['title' => 'Заголовок']);
$db->update('notes', ['title' => 'Другой'], ['id' => $id]);
$db->delete('notes', ['id' => $id]);
```

Всё, что пишется в несколько шагов, идёт через транзакцию. Вложенные вызовы
безопасны — внутренний работает в уже открытой:

```php
$db->transaction(function (Connection $db) use ($note): void {
    $note->delete();
    $db->delete('note_tags', ['note_id' => $note->id()]);
});
```

Соединение с MySQL воркер держит часами, поэтому обрыв (2006/2013, «server has gone
away») ловится, соединение поднимается заново и запрос повторяется один раз. Внутри
транзакции повтора нет — она всё равно уже потеряна.

## Построитель запросов

```php
$rows = $db->table('notes')
    ->select('id', 'title')
    ->where('user_id', 5)
    ->whereIn('status', ['new', 'open'])
    ->whereLike('title', 'отчёт')
    ->whereBetween('created_at', '2026-01-01', '2026-12-31')
    ->orderBy('id', 'desc')
    ->limit(20)
    ->get();
```

Скобки задаются замыканием, «или» — методами `orWhere`:

```php
$db->table('users')->where(function (Builder $query): void {
    $query->where('login', $needle)->orWhere('email', $needle);
});
```

Ещё есть `join`/`leftJoin`, `groupBy`, `having`, `when` (условие только если значение
чего-то стоит), `paginate`, `chunk`, `pluck`, `count`, `sum`, `max`, `exists`,
`increment`, `insert`, `update`, `delete`.

Три правила, на которых всё держится:

- **значения всегда уходят параметрами**, имена таблиц и колонок сверяются регуляркой —
  подставлять в SQL что попало нельзя;
- **каждое значение получает своё имя параметра** (`p0`, `p1`, …): MySQL работает
  с настоящими подготовленными выражениями и повтор имени не принимает;
- **удаление без условий запрещено** — «снести всю таблицу» должно писаться явно.

## Модели: Active Record

```php
final class Note extends Model
{
    protected static string $table = 'notes';          // можно не задавать: Note -> notes

    protected array $fillable = ['title', 'body'];      // что можно заполнять массово
    protected array $hidden   = ['secret'];             // что не уезжает в toArray и JSON
    protected array $casts    = ['pinned' => 'bool'];   // int, float, bool, json, datetime

    protected bool $timestamps = true;                  // created_at и updated_at сами
    protected bool $softDelete = true;                  // удаление помечает deleted_at

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
```

Выборка:

```php
Note::find(5);                       // null, если нет
Note::findOrFail(5);                 // исключение, которое ядро превратит в 404
Note::findBy('slug', 'otchyot');
Note::all();
Note::where('pinned', 1)->orderBy('id', 'desc')->get();
Note::query()->with('author')->paginate($page, 25);
Note::firstOrCreate(['slug' => 'x'], ['title' => 'Новая']);
```

Запись:

```php
$note = Note::create(['title' => 'Заголовок']);   // заполнит только fillable
$note->title = 'Другой';
$note->save();                                    // в UPDATE уйдут только изменённые поля
$note->update(['body' => 'Текст']);
$note->delete();                                  // мягко, если включено
$note->forceDelete();                             // насовсем
$note->restore();
```

`forceFill()` заполняет что угодно, минуя белый список, — только для своего кода:
данные из запроса туда пускать нельзя, иначе форма с дописанным полем поменяет
что угодно, вплоть до роли.

### Связи

```php
$this->hasMany(Note::class);                     // много чужих ссылается на нас
$this->hasOne(Profile::class);                   // одна чужая
$this->belongsTo(User::class, 'user_id');        // внешний ключ у нас
$this->belongsToMany(Tag::class, 'note_tags');   // через таблицу-связку
```

Обращение к связи как к свойству отдаёт результат, как к методу — саму связь,
на которую можно навесить условия:

```php
$author = $note->author;                          // модель или null
$fresh  = $user->notes()->where('pinned', 1)->get();
```

Связка «многие ко многим» умеет `attach`, `detach` и `sync`.

Списком связи подгружаются через `with()` — один запрос на связь вместо запроса
на каждую строку:

```php
foreach (Note::query()->with('author')->get() as $note) {
    echo $note->author->login;   // лишних запросов здесь уже нет
}
```

### Мягкое удаление

С `$softDelete = true` запись не исчезает, а помечается `deleted_at` и уходит
из обычных выборок. Достать её можно `withTrashed()` или `onlyTrashed()`, вернуть —
`restore()`, убрать насовсем — `forceDelete()`.

### События модели

`save()`, `delete()`, `forceDelete()` и `restore()` сами объявляют, что происходит, —
через ту же шину `Events`, что и всё остальное (`mail.sending`, `webhook.*`). Слушатель
узнаёт модель через `$payload['model']`, а не по имени события — событие одно на все
модели:

```php
use Rsgrinko\Proton\Events\Events;

Events::listen('model.saving', function (array $payload): void {
    if (!$payload['model'] instanceof \App\Models\Note) {
        return;
    }
    // …
});
```

«До» — `model.saving`, `model.creating`, `model.updating`, `model.deleting`,
`model.restoring`: слушатель может отменить операцию, вернув `false` — как отмена
письма через `mail.sending`. `model.updating` летит с ключом `changes` (что уйдёт
в `UPDATE`), `model.deleting`/`model.deleted` при `forceDelete()` — с `force => true`.

«После» — `model.created`, `model.updated`, `model.saved`, `model.deleted`,
`model.restored`: запись уже изменилась, отменять нечего, это просто уведомление.

`model.saving` летит и при создании, и при изменении — раньше своего `model.creating`
или `model.updating`. Если сохранять нечего (`save()` без изменений полей), ни одно
событие не звучит: это не действие, а нет-оп.

Хук — не замена `Policy` и не место для бизнес-правил, которые должны быть видны
в контроллере: он для сквозных вещей вроде сброса кэша или лога, которым всё равно,
через какой контроллер модель сохранили.

## Область видимости

`Scope` решает, чьи записи видно, и уезжает прямо в запрос — так фильтр нельзя забыть:

```php
public function index(Scope $scope): Response
{
    $notes = $scope->apply(Note::query())->orderBy('id', 'desc')->get();
}
```

Администратор (право `data.all`) видит всё, остальные — только свои записи. Подробности —
в [ACCESS.md](ACCESS.md).
