<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Database\Schema;

use InvalidArgumentException;

/**
 * Колонка таблицы. Тип задаётся отвлечённо (string, integer, dateTime), в SQL его
 * переводит Types — миграция пишется одинаково для обеих баз.
 *
 * Методы возвращают сам объект, поэтому описание читается строкой:
 * $table->string('login', 191)->nullable()->default('');
 */
final class Column
{
    private string $name;

    private string $type;

    private int $length;

    private int $scale = 2;

    private bool $nullable = false;

    private bool $primary = false;

    private bool $hasDefault = false;

    private mixed $default = null;

    public function __construct(string $name, string $type, int $length = 0, int $scale = 2)
    {
        $this->name   = $name;
        $this->type   = $type;
        $this->length = $length;
        $this->scale  = $scale;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function nullable(bool $nullable = true): self
    {
        $this->nullable = $nullable;

        return $this;
    }

    /**
     * Значение по умолчанию. Строки уходят в кавычках, числа — как есть.
     */
    public function default(mixed $value): self
    {
        $this->hasDefault = true;
        $this->default    = $value;

        return $this;
    }

    /**
     * Первичный ключ на самой колонке — для таблиц с текстовым ключом
     * (settings, migrations, counters), где автоинкремент не нужен.
     */
    public function primary(bool $primary = true): self
    {
        $this->primary = $primary;

        return $this;
    }

    /**
     * Определение колонки для CREATE TABLE или ALTER TABLE ADD COLUMN.
     */
    public function sql(Types $types): string
    {
        // Автоинкремент несёт первичный ключ и NOT NULL в самом типе
        if ($this->type === 'id') {
            return $this->name . ' ' . $types->id();
        }

        $sql = $this->name . ' ' . $this->typeSql($types);

        $sql .= $this->nullable ? ' NULL' : ' NOT NULL';

        if ($this->primary) {
            $sql .= ' PRIMARY KEY';
        }

        if ($this->hasDefault) {
            $sql .= ' DEFAULT ' . $this->defaultSql();
        }

        return $sql;
    }

    private function typeSql(Types $types): string
    {
        return match ($this->type) {
            'string'   => $types->string($this->length),
            'text'     => $types->text(),
            'longText' => $types->longText(),
            'integer'  => $types->integer(),
            'boolean'  => $types->boolean(),
            'decimal'  => $types->decimal($this->length, $this->scale),
            'float'    => $types->float(),
            'dateTime' => $types->dateTime(),
            'date'     => $types->date(),
            default    => throw new InvalidArgumentException('Неизвестный тип колонки: ' . $this->type),
        };
    }

    private function defaultSql(): string
    {
        if (is_bool($this->default)) {
            return $this->default ? '1' : '0';
        }

        if (is_int($this->default) || is_float($this->default)) {
            return (string) $this->default;
        }

        if ($this->default === null) {
            return 'NULL';
        }

        return "'" . str_replace("'", "''", (string) $this->default) . "'";
    }
}
