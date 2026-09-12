<?php

declare(strict_types=1);

namespace App\Migrations;

use Rsgrinko\Proton\Database\Migration;
use Rsgrinko\Proton\Database\Schema\Blueprint;

/**
 * Таблица заметок — демонстрационный раздел. Удаляется вместе с app/Models/Note.php,
 * контроллерами заметок, их маршрутами и шаблонами.
 */
final class CreateNotes extends Migration
{
    public function up(): void
    {
        $this->create('notes', function (Blueprint $table): void {
            $table->id();
            // Владелец: по нему работает область видимости — свои заметки видно,
            // чужие для обычного пользователя не существуют
            $table->foreignId('user_id');
            $table->string('title', 191);
            $table->text('body')->nullable();
            $table->string('slug', 191)->default('');
            $table->integer('pinned')->default(0);
            $table->string('file_path', 255)->default('');
            $table->string('file_name', 191)->default('');
            $table->timestamps();
            $table->softDeletes();

            $table->index('idx_notes_title', 'title');
        });
    }

    public function down(): void
    {
        $this->drop('notes');
    }
}
