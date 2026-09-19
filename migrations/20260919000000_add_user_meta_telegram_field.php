<?php

declare(strict_types=1);

namespace App\Migrations;

use Rsgrinko\Proton\Database\Migration;

/**
 * Добавление кастомного поля профиля Telegram
 */
final class AddUserMetaTelegramField extends Migration
{
    private const META_FIELDS = [
        [
            'field_key'   => 'telegram',
            'label'       => 'Telegram',
            'type'        => 'string',
            'description' => 'Профиль Telegram в формате @nickname',
        ],
    ];
    public function up(): void
    {
        foreach (self::META_FIELDS as $field) {
            $this->statement(
                'INSERT INTO user_fields (field_key, label, type, description, sort, created_at, updated_at)
                 VALUES (:field_key, :label, :type, :description, :sort, :created_at, :updated_at)',
                [
                    'field_key'   => $field['field_key'],
                    'label'       => $field['label'],
                    'type'        => $field['type'],
                    'description' => $field['description'],
                    'sort'        => 0,
                    'created_at'  => $this->now(),
                    'updated_at'  => $this->now(),
                ]
            );
        }
    }

    public function down(): void
    {
        foreach (self::META_FIELDS as $field) {
            $this->statement(
                'DELETE FROM user_fields WHERE field_key = :field_key',
                ['field_key' => $field['field_key']]
            );
        }
    }
}
