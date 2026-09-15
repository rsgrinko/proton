<?php

declare(strict_types=1);

namespace App\Migrations;

use Rsgrinko\Proton\Database\Migration;
use Rsgrinko\Proton\Database\Schema\Blueprint;

/**
 * Вложения: несколько файлов к любой записи.
 *
 * Раньше файл жил колонкой в самой записи — один и только один. Отдельная
 * таблица снимает это ограничение и заодно даёт понять, какие файлы в
 * хранилище вообще кому-то нужны: всё остальное — сироты.
 */
final class CreateAttachments extends Migration
{
    public function up(): void
    {
        $this->create('attachments', function (Blueprint $table): void {
            $table->id();
            // К чему приложено: раздел и запись
            $table->string('entity', 64);
            $table->string('entity_id', 64);
            $table->string('path', 255);
            $table->string('name', 191);
            $table->string('mime', 128)->default('');
            $table->integer('size')->default(0);
            $table->foreignId('user_id')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->index('idx_attachments_owner', ['entity', 'entity_id']);
            $table->unique('idx_attachments_path', 'path');
        });
    }

    public function down(): void
    {
        $this->drop('attachments');
    }
}
