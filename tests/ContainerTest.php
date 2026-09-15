<?php

declare(strict_types=1);

/**
 * Контейнер: привязки, singleton, сборка аргументов и вызов методов.
 */

use Rsgrinko\Proton\Models\User;
use Rsgrinko\Proton\Support\Container;
use Rsgrinko\Proton\Support\Logger;
use Rsgrinko\Proton\Support\ProtonException;

interface ContainerTestStorage
{
    public function name(): string;
}

final class ContainerTestLocal implements ContainerTestStorage
{
    public function name(): string
    {
        return 'локальное';
    }
}

final class ContainerTestReport
{
    public function __construct(public readonly Logger $logger)
    {
    }

    public function build(string $period, Logger $logger): string
    {
        return $period . ':' . spl_object_id($logger);
    }
}

final class ContainerTestLeft
{
    public function __construct(public readonly ContainerTestRight $right)
    {
    }
}

final class ContainerTestRight
{
    public function __construct(public readonly ContainerTestLeft $left)
    {
    }
}

test('контейнер: привязка интерфейса к классу', function (): void {
    $container = new Container();
    $container->bind(ContainerTestStorage::class, ContainerTestLocal::class);

    assertSame('локальное', $container->make(ContainerTestStorage::class)->name());
    assertTrue($container->has(ContainerTestStorage::class));

    $error = assertThrows(static fn () => (new Container())->make(ContainerTestStorage::class));

    assertTrue($error instanceof ProtonException, 'интерфейс без привязки не собирается');
});

test('контейнер: singleton один на процесс, обычная привязка — каждый раз новая', function (): void {
    $container = new Container();

    $container->singleton(ContainerTestLocal::class);

    assertTrue($container->make(ContainerTestLocal::class) === $container->make(ContainerTestLocal::class));

    // Без привязки класс собирается заново: общими бывают только те, кого просили
    assertFalse($container->make(ContainerTestReport::class) === $container->make(ContainerTestReport::class));
});

test('контейнер: замыкание получает контейнер и создаёт объект само', function (): void {
    $container = new Container();

    $container->singleton(Logger::class, static fn (): Logger => new Logger('через замыкание'));

    $report = $container->make(ContainerTestReport::class);

    assertTrue($report->logger === $container->make(Logger::class));
});

test('контейнер: вызов метода подставляет и значения, и зависимости', function (): void {
    $container = new Container();
    $container->singleton(Logger::class, static fn (): Logger => new Logger('вызов'));

    $result = $container->call([ContainerTestReport::class, 'build'], ['period' => 'месяц']);

    assertSame('месяц:' . spl_object_id($container->make(Logger::class)), $result);

    // Значения, которое не вывести по типу, не хватает — и об этом сказано прямо
    $error = assertThrows(static fn () => $container->call([ContainerTestReport::class, 'build']));

    assertContains('period', $error->getMessage());
    assertContains('ContainerTestReport::build', $error->getMessage());
});

test('контейнер: кольцо зависимостей ловится, а не вешает процесс', function (): void {
    $container = new Container();

    $container->bind(ContainerTestLeft::class)->bind(ContainerTestRight::class);

    $error = assertThrows(static fn () => $container->make(ContainerTestLeft::class));

    assertContains('закольцевались', $error->getMessage());
});

test('контейнер: модели и чужие классы сам не собирает', function (): void {
    $container = new Container();

    assertFalse($container->has(User::class), 'модель — это запись, а не зависимость');
    assertFalse($container->has(DateTimeZone::class));

    $error = assertThrows(static fn () => $container->make(DateTimeZone::class));

    assertTrue($error instanceof ProtonException);
});

test('контейнер: реестр services.php разбирается', function (): void {
    $container = new Container();

    $container->register([
        'bind'      => [ContainerTestStorage::class => ContainerTestLocal::class],
        'singleton' => [Logger::class => static fn (): Logger => new Logger('из реестра')],
    ]);

    assertSame('локальное', $container->make(ContainerTestStorage::class)->name());
    assertTrue($container->make(Logger::class) === $container->make(Logger::class));
});
