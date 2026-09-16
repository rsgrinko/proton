<?php

declare(strict_types=1);

namespace App\Migrations;

use Rsgrinko\Proton\Database\Migration;
use Rsgrinko\Proton\Database\Schema\Blueprint;

/**
 * Свои поля профиля — те, что заранее в коде не предусмотришь: сегодня нужен
 * ИД Яндекс.Метрики, завтра — табельный номер. Поле заводит администратор в
 * панели, оно сразу появляется в анкете у каждого пользователя, а значения
 * лежат отдельно от определения — одна строка на пару «пользователь + поле».
 */
final class CreateUserFields extends Migration
{
    public function up(): void
    {
        $this->create('user_fields', function (Blueprint $table): void {
            $table->id();
            // Не "key" — зарезервированное слово в MySQL, ломает CREATE TABLE
            $table->string('field_key', 100);
            $table->string('label', 191);
            $table->string('type', 20)->default('string');
            $table->string('description', 255)->default('');
            $table->integer('sort')->default(0);
            $table->timestamps();

            $table->unique('idx_user_fields_key', 'field_key');
        });

        $this->create('user_field_values', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id');
            $table->foreignId('field_id');
            $table->text('value')->nullable();
            $table->timestamps();

            $table->unique('idx_user_field_values_pair', ['user_id', 'field_id']);
        });
    }

    public function down(): void
    {
        $this->drop('user_field_values');
        $this->drop('user_fields');
    }
}
