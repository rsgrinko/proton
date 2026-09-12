<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Database\Schema;

use Rsgrinko\Proton\Database\Connection;

/**
 * Типы колонок под конкретный драйвер. Всё, что различается между sqlite и mysql,
 * собрано здесь: миграции про диалект не знают вовсе.
 */
final class Types
{
    private Connection $db;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    public function isSqlite(): bool
    {
        return $this->db->isSqlite();
    }

    public function id(): string
    {
        return $this->db->isSqlite()
            ? 'INTEGER PRIMARY KEY AUTOINCREMENT'
            : 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
    }

    public function string(int $length): string
    {
        return $this->db->isSqlite() ? 'TEXT' : 'VARCHAR(' . $length . ')';
    }

    public function text(): string
    {
        return 'TEXT';
    }

    public function longText(): string
    {
        return $this->db->isSqlite() ? 'TEXT' : 'LONGTEXT';
    }

    public function integer(): string
    {
        return $this->db->isSqlite() ? 'INTEGER' : 'BIGINT';
    }

    public function boolean(): string
    {
        return $this->db->isSqlite() ? 'INTEGER' : 'TINYINT(1)';
    }

    public function decimal(int $total, int $scale): string
    {
        return 'DECIMAL(' . $total . ',' . $scale . ')';
    }

    public function float(): string
    {
        return $this->db->isSqlite() ? 'REAL' : 'DOUBLE';
    }

    public function dateTime(): string
    {
        return $this->db->isSqlite() ? 'TEXT' : 'DATETIME';
    }

    public function date(): string
    {
        return $this->db->isSqlite() ? 'TEXT' : 'DATE';
    }

    /**
     * Хвост CREATE TABLE: движок и кодировка для MySQL, пусто для SQLite.
     */
    public function tableSuffix(): string
    {
        return $this->db->isSqlite() ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    }
}
