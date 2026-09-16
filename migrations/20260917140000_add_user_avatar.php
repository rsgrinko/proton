<?php

declare(strict_types=1);

namespace App\Migrations;

use Rsgrinko\Proton\Database\Migration;
use Rsgrinko\Proton\Database\Schema\Blueprint;

/**
 * Фото профиля. Путь к файлу и его MIME — отдельно от общего хранилища
 * вложений (`attachments`): аватар не «прикладывается» к записи, у него всегда
 * ровно один текущий файл на пользователя, который просто перезаписывается.
 */
final class AddUserAvatar extends Migration
{
    public function up(): void
    {
        $this->table('users', function (Blueprint $table): void {
            $table->string('avatar_path', 255)->default('');
            $table->string('avatar_mime', 100)->default('');
        });
    }

    public function down(): void
    {
        $this->table('users', function (Blueprint $table): void {
            $table->dropColumn('avatar_path');
            $table->dropColumn('avatar_mime');
        });
    }
}
