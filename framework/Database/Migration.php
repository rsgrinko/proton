<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Database;

use Rsgrinko\Proton\Database\Schema\Blueprint;
use Rsgrinko\Proton\Database\Schema\Builder;

/**
 * Одна миграция — один файл в каталоге migrations/ с именем вида
 * `20260912093000_create_users.php` и классом `CreateUsers` в App\Migrations.
 *
 * up() накатывает изменение, down() отменяет его. Имя файла — это и есть имя
 * миграции в таблице migrations, поэтому переименовывать файл нельзя: миграция
 * станет новой и применится второй раз.
 */
abstract class Migration
{
    protected Builder $schema;

    public function __construct(Builder $schema)
    {
        $this->schema = $schema;
    }

    /**
     * Что миграция делает.
     */
    abstract public function up(): void;

    /**
     * Как это отменить. Откат обязан быть честным: это проверяется тестом,
     * который накатывает всё, откатывает и накатывает снова.
     */
    abstract public function down(): void;

    /**
     * @param callable(Blueprint): void $definition
     */
    protected function create(string $table, callable $definition): void
    {
        $this->schema->create($table, $definition);
    }

    /**
     * @param callable(Blueprint): void $definition
     */
    protected function table(string $table, callable $definition): void
    {
        $this->schema->table($table, $definition);
    }

    protected function drop(string $table): void
    {
        $this->schema->drop($table);
    }

    /**
     * Свой запрос — только с параметрами, а не склейкой строк.
     *
     * @param array<string, mixed> $params
     */
    protected function statement(string $sql, array $params = []): void
    {
        $this->schema->statement($sql, $params);
    }

    protected function isSqlite(): bool
    {
        return $this->schema->isSqlite();
    }

    /**
     * Идёт ли `migrate --pretend`. Шагу с данными это важно: в этом режиме
     * таблиц в базе ещё нет, и проверка «нет ли уже такой записи» упадёт.
     */
    protected function pretending(): bool
    {
        return $this->schema->pretending();
    }

    protected function db(): Connection
    {
        return $this->schema->db();
    }

    /**
     * Текущее время в том виде, в каком мы храним даты.
     */
    protected function now(): string
    {
        return Connection::now();
    }
}
