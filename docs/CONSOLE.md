# Консоль и генераторы

Одна утилита на всё: `php bin/proton <команда>`. Справка собирается из самих команд,
руками её править не нужно:

```bash
php bin/proton help          # все команды с описанием
php bin/proton status        # самопроверка: окружение, база, ключи, очередь, почта
```

## Что есть в коробке

**Установка и обслуживание**

| Команда | Что делает |
|---------|-----------|
| `install [--force]` | установить приложение: `.env`, ключ, база, схема, администратор |
| `migrate [--pretend]` | применить новые миграции (`--pretend` — только показать запросы) |
| `migrate:status` | что применено, что ждёт, чего нет в коде |
| `migrate:rollback [--steps=1]` | откатить последнюю пачку миграций |
| `seed` | залить стартовые данные: `database/seed.php`, если он заведён (файл возвращает функцию) |
| `app:key [--force] [--show]` | создать `APP_KEY` и записать в `.env` |
| `route:list [строка]` | карта адресов: путь, обработчик, прослойки, имя |
| `cache:clear` | очистить кэш |
| `logs:purge [--days=30]` | удалить старые файлы логов |

**Разработка**

| Команда | Что делает |
|---------|-----------|
| `serve [--host=] [--port=] [--tries=]` | встроенный сервер; занятый порт — возьмёт следующий |
| `test [--filter=] [--stop-on-failure] [--shuffle]` | прогнать тесты |
| `make:crud <Имя> [--fields=…]` | раздел целиком: модель, миграция, контроллер, вьюхи, тест |
| `make:model`, `make:controller [--admin]`, `make:migration [--table=]`, `make:job`, `make:command`, `make:test` | заготовки кода |
| `tinker [--execute=код]` | интерактивная консоль с доступом к моделям и конфигу |

**Очередь и расписание**: `worker [--once] [--queue=]`, `worker:restart`,
`schedule:run [--list]`, `queue:status [--failed]`, `queue:retry <id\|--failed>`,
`queue:purge [--days=]` — подробности в [QUEUE.md](QUEUE.md).

**Пользователи и доступ**: `user:create`, `user:list`, `user:password`, `user:delete`,
`role:list`, `key:create`, `key:list`, `key:revoke` — подробности в [ACCESS.md](ACCESS.md).

**Почта**: `mail:test <адрес> [--queue]` — см. [MAIL.md](MAIL.md).

**Обновление ядра**: `core:version`, `core:bump [patch\|minor\|major] [--note=] [--breaking]`,
`core:check [--baseline]`, `core:diff`/`core:sync [--apply] (--path=<каталог>\|--remote)`,
`core:latest` — подробности в [CORE_UPDATES.md](CORE_UPDATES.md) и [UPDATING.md](UPDATING.md).

## Раздел целиком

Раздел — это шесть файлов, и все они должны сойтись друг с другом: имена полей в форме,
правила проверки, колонки таблицы, подстановки во вьюхах. `make:crud` собирает их разом:

```bash
php bin/proton make:crud Order \
    --fields="title:string:Название,total:decimal:Сумма,paid:bool:Оплачен,due:date:Срок" \
    --title="Заказы"
```

Получаются `app/Models/Order.php`, `app/Controllers/Web/OrdersController.php`, миграция,
три вьюхи (список с поиском и страницами, форма, карточка) и тест, который нажимает
кнопки настоящим ядром. Поле описывается как `имя:тип[:подпись]`; типы — `string`,
`text`, `int`, `decimal`, `bool`, `date`, `datetime`, `email`. Подпись увидит человек
в форме и в сообщении об ошибке.

Опции: `--title=` — название раздела для заголовков и меню (по умолчанию имя класса),
`--no-soft-delete` — удалять записи насовсем, `--force` — перезаписать существующие
файлы. Без `--force` команда не тронет ни одного файла, если хоть один уже есть:
раздел, собранный наполовину, хуже, чем не собранный вовсе.

Три вещи команда не правит, а печатает готовыми кусками — маршруты (`routes/web.php`),
права (`config/permissions.php`) и пункт меню (`config/menu.php`). Это код приложения,
и лезть в него автоматической правкой опаснее, чем скопировать три строки. Дальше —
`php bin/proton migrate` и `php bin/proton test`.

Права в сгенерированном разделе разделены как везде: `<раздел>.view` на просмотр,
`<раздел>.manage` на правку; список уважает область видимости, поэтому чужая запись
в него не попадёт.

## Остальные генераторы

Заготовки лежат в `stubs/` и правятся под свой стиль — генератор просто подставляет
имена:

```bash
php bin/proton make:model Order              # app/Models/Order.php
php bin/proton make:controller Orders        # app/Controllers/Web/OrdersController.php
php bin/proton make:controller Orders --admin # app/Controllers/Admin/OrdersController.php
php bin/proton make:migration create_orders --table=orders
php bin/proton make:job SendInvoiceJob
php bin/proton make:command ImportOrders
php bin/proton make:test Orders
```

Готовый файл команда не перезаписывает — говорит, что он уже есть.

## Tinker

Консоль без отдельного скрипта ради разовой проверки — код на php выполняется сразу
после ввода:

```bash
php bin/proton tinker
>>> \App\Models\Note::query()->count()
3
>>> $n = \App\Models\Note::find(1);
>>> $n->title
"Первая заметка"
```

Классы — с полным пространством имён, автоимпорта нет нарочно: меньше магии, видно,
что откуда. Переменные между строками сохраняются, но через `extract()`/
`get_defined_vars()`, а не настоящую область видимости, поэтому пара служебных имён
(`code`, `vars`, начинающиеся с `__`) как обычные переменные не заведутся — на практике
не мешает.

Для одноразовой команды без интерактивного ввода — `--execute`, код можно разбить
на несколько строк через `\n`, переменные общие:

```bash
php bin/proton tinker --execute='$u = \Rsgrinko\Proton\Models\User::find(1);
$u->email'
```

Ошибка одной строки не прерывает остальные — код возврата станет 1, но выполнение
продолжится, чтобы виднее было, что именно сломалось.

## Своя команда

Класс в `app/Commands` плюс строка в реестре `config/commands.php` — иначе команда
не появится ни в справке, ни в разборе аргументов. Живой пример в приложении —
`app/Commands/NotesSeedCommand.php` (`php bin/proton notes:seed <логин> [--count=]`):
заводит демонстрационные заметки, чтобы на пустой базе было на чём проверить
список, фильтры и страницы.

```php
final class ImportOrdersCommand extends Command
{
    public function name(): string { return 'orders:import'; }
    public function description(): string { return 'Загрузить заказы из файла'; }
    public function usage(): string { return 'orders:import <файл> [--dry-run]'; }

    public function run(): int
    {
        $file = $this->arg(0);

        if ($file === '') {
            $this->fail('Укажите файл');

            return 1;
        }

        if ($this->hasOption('dry-run')) {
            $this->line('Только показываю, ничего не пишу');
        }

        $this->table(['id', 'Клиент'], [[1, 'ООО «Ромашка»']]);
        $this->ok('Готово');

        return 0;
    }
}
```

Аргументы и опции разбирает `Application` и отдаёт готовыми: позиционные — через
`$this->arg(0)`, опции `--name=value` — через `$this->option('name')` и
`$this->hasOption('name')`. Код возврата: 0 — всё хорошо, иначе ошибка (её увидит cron
и systemd).

Печатать нужно через помощники, а не `echo`: в тестах вывод подменяется, и `echo`
уедет мимо.

| Помощник | Зачем |
|----------|-------|
| `line()` | обычная строка |
| `ok()`, `fail()` | заметный итог: успех и ошибка |
| `table($headers, $rows)` | колонки, ширина считается по содержимому |
| `pad($text, $width)` | одна колонка: `str_pad` считает байты и разъезжается на русских словах |
| `ask($вопрос, $поумолчанию)` | спросить; в неинтерактивном запуске сразу вернёт значение по умолчанию |
| `confirm($вопрос)` | подтверждение опасного действия; `--force` отвечает «да» без вопроса |

`ask()` и `confirm()` не вешают команду в cron: без терминала они берут значение
по умолчанию, а `confirm()` без `--force` отвечает «нет».

## Тесты на команды

Команда запускается без процесса — с подменённым выводом:

```php
$command = (new UserListCommand())->withInput([], [], static function (string $line) use (&$out): void {
    $out .= $line . "\n";
});

assertSame(0, $command->run());
assertContains('admin', $out);
```

Так проверяется и код возврата, и то, что команда напечатала.
