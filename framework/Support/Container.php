<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Support;

use Closure;
use ReflectionClass;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;
use Rsgrinko\Proton\Database\Connection;
use Rsgrinko\Proton\Database\Model\Model;

/**
 * Сборщик объектов: создаёт класс, подставляя в конструктор то, что он просит
 * по типам, а во что превращается интерфейс — решает реестр привязок.
 *
 *     $container->bind(Storage::class, LocalStorage::class);   // каждый раз новый
 *     $container->singleton(PriceList::class);                 // один на процесс
 *     $container->set(Clock::class, new FrozenClock());        // готовый объект
 *     $container->make(NotesController::class);
 *     $container->call([$report, 'build'], ['period' => 'месяц']);
 *
 * Привязки приложения перечисляются в `config/services.php` и подхватываются
 * сами при первом обращении.
 *
 * Своё поведение по умолчанию: класс без привязки собирается каждый раз заново.
 * Общими бывают только те, кого об этом попросили (`singleton`, `set`), — иначе
 * долгий процесс воркера копил бы объекты с устаревшими настройками внутри.
 */
final class Container
{
    /** @var array<string, object> Готовые объекты: общие на весь процесс */
    private array $shared = [];

    /** @var array<string, array{concrete: Closure|string, shared: bool}> Привязки */
    private array $bindings = [];

    /** @var array<int, string> Что собираем прямо сейчас — им ловятся кольца */
    private array $building = [];

    private bool $booted = false;

    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Подменить контейнер целиком — нужно тестам.
     */
    public static function setInstance(?self $container): void
    {
        self::$instance = $container;
    }

    /**
     * Положить готовый объект: его и будут получать все, кто просит этот тип.
     */
    public function set(string $abstract, object $object): self
    {
        $this->shared[$abstract] = $object;

        return $this;
    }

    /**
     * Привязка: что создавать, когда просят $abstract. Без второго аргумента —
     * сам класс; строка — класс-замена (обычно реализация интерфейса);
     * замыкание — своя сборка, ему передаётся контейнер.
     */
    public function bind(string $abstract, Closure|string|null $concrete = null, bool $shared = false): self
    {
        $this->bindings[$abstract] = ['concrete' => $concrete ?? $abstract, 'shared' => $shared];

        unset($this->shared[$abstract]);

        return $this;
    }

    /**
     * То же, но объект создаётся один раз на процесс.
     */
    public function singleton(string $abstract, Closure|string|null $concrete = null): self
    {
        return $this->bind($abstract, $concrete, true);
    }

    /**
     * Знает ли контейнер, что делать с типом.
     */
    public function has(string $abstract): bool
    {
        $this->boot();

        return isset($this->shared[$abstract]) || isset($this->bindings[$abstract]) || $this->own($abstract);
    }

    /**
     * Создаёт объект со всеми зависимостями. Значения, которые не вывести по
     * типу (числа, строки), передаются по имени аргумента.
     *
     * @template T of object
     *
     * @param class-string<T>      $abstract
     * @param array<string, mixed> $arguments
     *
     * @return T
     */
    public function make(string $abstract, array $arguments = []): object
    {
        $this->boot();

        if (isset($this->shared[$abstract])) {
            /** @var T $shared */
            $shared = $this->shared[$abstract];

            return $shared;
        }

        if (in_array($abstract, $this->building, true)) {
            throw new ProtonException(
                'Зависимости закольцевались: ' . implode(' → ', [...$this->building, $abstract])
            );
        }

        $this->building[] = $abstract;

        try {
            $object = $this->build($abstract, $arguments);
        } finally {
            array_pop($this->building);
        }

        if (($this->bindings[$abstract]['shared'] ?? false) === true) {
            $this->shared[$abstract] = $object;
        }

        /** @var T $object */
        return $object;
    }

    /**
     * Вызывает метод или функцию, подставляя аргументы: по имени — из
     * $arguments, остальное — по типам из контейнера.
     *
     * @param callable|array{0: object|class-string, 1: string} $callback
     * @param array<string, mixed>                              $arguments
     */
    public function call(callable|array $callback, array $arguments = []): mixed
    {
        $this->boot();

        if (is_array($callback)) {
            $object    = is_string($callback[0]) ? $this->make($callback[0]) : $callback[0];
            $method    = new ReflectionMethod($object, $callback[1]);
            $arguments = $this->arguments($method, $arguments);

            return $method->invokeArgs($object, $arguments);
        }

        $function = new ReflectionFunction(Closure::fromCallable($callback));

        return $function->invokeArgs($this->arguments($function, $arguments));
    }

    /**
     * Аргументы для вызова: сначала переданные по имени, потом собранные по типу.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<int, mixed>
     */
    public function arguments(ReflectionFunctionAbstract $function, array $arguments = []): array
    {
        $values = [];

        foreach ($function->getParameters() as $parameter) {
            $name = $parameter->getName();
            $type = $parameter->getType();

            if (array_key_exists($name, $arguments)) {
                $values[] = $arguments[$name];

                continue;
            }

            if ($type instanceof ReflectionNamedType && !$type->isBuiltin() && $this->has($type->getName())) {
                $values[] = $this->make($type->getName());

                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $values[] = $parameter->getDefaultValue();

                continue;
            }

            if ($parameter->allowsNull()) {
                $values[] = null;

                continue;
            }

            throw new ProtonException(
                'Не могу подставить «' . $name . '» в ' . $this->where($function)
            );
        }

        return $values;
    }

    /**
     * Привязки приложения из config/services.php.
     *
     * @param array{bind?: array<string, Closure|string>, singleton?: array<string, Closure|string>} $services
     */
    public function register(array $services): self
    {
        foreach ((array) ($services['bind'] ?? []) as $abstract => $concrete) {
            $this->bind((string) $abstract, $concrete);
        }

        foreach ((array) ($services['singleton'] ?? []) as $abstract => $concrete) {
            $this->singleton((string) $abstract, $concrete);
        }

        return $this;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function build(string $abstract, array $arguments): object
    {
        $concrete = $this->bindings[$abstract]['concrete'] ?? $abstract;

        if ($concrete instanceof Closure) {
            $object = $concrete($this, $arguments);

            if (!is_object($object)) {
                throw new ProtonException('Сборщик для ' . $abstract . ' вернул не объект');
            }

            return $object;
        }

        if ($concrete !== $abstract) {
            return $this->make($concrete, $arguments);
        }

        if (!class_exists($concrete)) {
            throw new ProtonException(
                'Нечего создать для ' . $abstract . ': это интерфейс или неизвестный класс, привязки для него нет'
            );
        }


        $reflection = new ReflectionClass($concrete);

        if (!$reflection->isInstantiable()) {
            throw new ProtonException('Класс ' . $concrete . ' сам по себе не создаётся — нужна привязка');
        }

        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return new $concrete();
        }

        return $reflection->newInstanceArgs($this->arguments($constructor, $arguments));
    }

    /**
     * Свой ли это класс. По типу подставляются только свои классы и то, у чего
     * есть привязка: чужой объект слишком легко получить не с теми настройками.
     * Прямой `make()` соберёт и чужой класс, если сможет, — там просят явно.
     */
    private function own(string $class): bool
    {
        if (!str_starts_with($class, 'Rsgrinko\\Proton\\') && !str_starts_with($class, 'App\\')) {
            return false;
        }

        // Модель — это строка таблицы, а не зависимость: собранная контейнером,
        // она пришла бы пустой, и вместо понятной ошибки получилась бы тишина
        return class_exists($class) && !is_subclass_of($class, Model::class);
    }

    /**
     * Где именно не хватило аргумента — иначе по сообщению не найти место.
     */
    private function where(ReflectionFunctionAbstract $function): string
    {
        if ($function instanceof ReflectionMethod) {
            return $function->getDeclaringClass()->getName() . '::' . $function->getName() . '()';
        }

        return $function->getName() . '()';
    }

    /**
     * Привязки ядра и приложения. Читаются один раз, лениво: контейнер создаётся
     * и в консоли, и в воркере, а конфиг может быть ещё не нужен.
     */
    private function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;

        // Подключение к базе одно на процесс и создаётся не конструктором
        $this->singleton(Connection::class, static fn (): Connection => Connection::instance());

        $file = APP_ROOT . '/config/services.php';

        if (is_file($file)) {
            /** @var array{bind?: array<string, Closure|string>, singleton?: array<string, Closure|string>} $services */
            $services = (array) require $file;

            $this->register($services);
        }
    }
}
