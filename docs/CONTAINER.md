# Контейнер зависимостей

`Support\Container` собирает объекты и подставляет их туда, где они нужны:
в конструкторы контроллеров, в действия маршрутов, в любой вызов через `call()`.

Свои классы (`App\`, `Rsgrinko\Proton\`) он собирает без всяких записей — достаточно
типа. Реестр нужен интерфейсам, чужим классам и всему, чему при сборке нужны
настройки.

## Привязки

```php
$container = Container::instance();

$container->bind(Storage::class, LocalStorage::class);   // каждый раз новый объект
$container->singleton(PriceList::class);                 // один на процесс
$container->set(Clock::class, new FrozenClock());        // готовый объект (тесты)
$container->bind(HttpClient::class, static fn (Container $c): HttpClient => new HttpClient(30));
```

Привязки приложения живут в `config/services.php` и подхватываются сами:

```php
return [
    'bind' => [
        App\Contracts\Storage::class => App\Services\LocalStorage::class,
    ],
    'singleton' => [
        App\Services\PriceList::class => static fn (Container $c) => new PriceList(
            $c->make(Rsgrinko\Proton\Database\Connection::class)
        ),
    ],
];
```

Класс без привязки собирается **каждый раз заново**. Общими бывают только те, кого
об этом попросили: долгий процесс воркера иначе копил бы объекты с устаревшими
настройками внутри.

## Сборка и вызов

```php
$controller = $container->make(NotesController::class);
$report     = $container->make(MonthReport::class, ['month' => '2026-09']);  // скаляр по имени

$container->call([$report, 'build'], ['period' => 'месяц']);
```

В `make()` и `call()` аргументы ищутся так: сначала переданные по имени, потом
по типу из контейнера, потом значение по умолчанию, потом `null` для допускающего
его параметра. Не нашлось — исключение с именем аргумента и местом:
«Не могу подставить «period» в App\Reports\MonthReport::build()».

Кольцо зависимостей ловится и называется целиком:
«Зависимости закольцевались: A → B → A», а не вешает процесс.

## В действиях маршрутов

Роутер подставляет в метод контроллера `Request`, параметры адреса (`{id}`),
атрибуты запроса от прослоек (`$user`, `$viewer`, `$scope`) — и всё остальное
с типом-классом собирает контейнер:

```php
public function index(Request $request, Scope $scope, PriceList $prices): Response
```

Имена атрибутов важнее типов: `User $current` работать не будет, потому что
атрибут называется `user`. Модели контейнер не собирает вовсе — модель это
строка таблицы, а не зависимость, и подставленная пустая модель молчала бы
вместо понятной ошибки.

## Тесты

Контейнер подменяется целиком:

```php
Container::setInstance(null);              // забыть всё, что настроили
$container = new Container();
$container->set(HttpClient::class, new FakeClient());
Container::setInstance($container);
```

Раннер делает это сам между тестами-хозяевами (`withOwnDatabase`).
