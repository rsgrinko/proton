<?php

declare(strict_types=1);

namespace App\Migrations;

use Rsgrinko\Proton\Database\Migration;
use Rsgrinko\Proton\Database\Schema\Blueprint;

/**
 * Комментарии: обсуждение к любой записи, не только к заметкам.
 *
 * Та же идея, что у вложений (`attachments`) — запись не привязана внешним
 * ключом к конкретной таблице, а несёт раздел и номер записи сама. Так
 * комментарии заводятся под любой раздел без миграции: достаточно вызвать
 * `Comment::add()` из своего контроллера.
 */
final class CreateComments extends Migration
{
    public function up(): void
    {
        $this->create('comments', function (Blueprint $table): void {
            $table->id();
            // К чему относится: раздел и запись
            $table->string('entity', 64);
            $table->string('entity_id', 64);
            $table->foreignId('user_id')->nullable();
            $table->text('body');
            $table->dateTime('created_at')->nullable();

            $table->index('idx_comments_owner', ['entity', 'entity_id']);
        });
    }

    public function down(): void
    {
        $this->drop('comments');
    }
}
