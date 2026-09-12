<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Support;

use ReflectionClass;
use ReflectionNamedType;
use Rsgrinko\Proton\Database\Connection;

/**
 * Маленький сборщик объектов: создаёт класс, подставляя в конструктор то, что он
 * просит по типам. Нужен роутеру, чтобы контроллеры получали зависимости, а не
 * создавали их сами — тогда в тестах можно подложить свои.
 *
 * Никакой магии: свои классы (Rsgrinko\Proton\ и App\), общее подключение к базе
 * и значения по умолчанию.
 */
final class Container
{
    /** @var array<string, object> Уже созданные объекты */
    private array $shared = [];

    /** @var array<string, callable> Свои сборщики: класс => функция */
    private array $factories = [];

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
    public function set(string $class, object $object): self
    {
        $this->shared[$class] = $object;

        return $this;
    }

    /**
     * Своя сборка типа — когда конструктор просит то, чего контейнер не знает.
     */
    public function bind(string $class, callable $factory): self
    {
        $this->factories[$class] = $factory;

        return $this;
    }

    /**
     * Создаёт класс со всеми зависимостями.
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    public function make(string $class): object
    {
        if (isset($this->shared[$class])) {
            /** @var T $shared */
            $shared = $this->shared[$class];

            return $shared;
        }

        if (isset($this->factories[$class])) {
            /** @var T $built */
            $built = ($this->factories[$class])($this);

            return $this->shared[$class] = $built;
        }

        // Подключение к базе одно на процесс
        if ($class === Connection::class) {
            /** @var T $connection */
            $connection = Connection::instance();

            return $connection;
        }

        $reflection  = new ReflectionClass($class);
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            /** @var T $object */
            $object = new $class();

            return $this->shared[$class] = $object;
        }

        $arguments = [];

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType && !$type->isBuiltin() && $this->known($type->getName())) {
                $arguments[] = $this->make($type->getName());

                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();

                continue;
            }

            if ($parameter->allowsNull()) {
                $arguments[] = null;

                continue;
            }

            throw new ProtonException(
                'Не могу собрать ' . $class . ': непонятно, что передать в «' . $parameter->getName() . '»'
            );
        }

        /** @var T $object */
        $object = $reflection->newInstanceArgs($arguments);

        return $this->shared[$class] = $object;
    }

    /**
     * Свой ли это класс. Чужие (и встроенные) контейнер не собирает — там слишком
     * легко получить объект не с теми настройками.
     */
    private function known(string $class): bool
    {
        return str_starts_with($class, 'Rsgrinko\\Proton\\') || str_starts_with($class, 'App\\');
    }
}
