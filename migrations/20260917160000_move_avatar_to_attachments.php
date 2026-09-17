<?php

declare(strict_types=1);

namespace App\Migrations;

use Rsgrinko\Proton\Database\Migration;
use Rsgrinko\Proton\Database\Schema\Blueprint;

/**
 * Аватар переезжает в attachments — как и любой другой файл к записи, а не
 * отдельной парой колонок на пользователе (entity = 'avatar', entity_id —
 * id пользователя). Ровно это attachments уже умеет: заводить под каждый
 * новый файл профиля свою пару колонок в users незачем.
 */
final class MoveAvatarToAttachments extends Migration
{
    public function up(): void
    {
        if (!$this->pretending()) {
            $this->migrateExisting();
        }

        $this->table('users', function (Blueprint $table): void {
            $table->dropColumn('avatar_path');
            $table->dropColumn('avatar_mime');
        });
    }

    public function down(): void
    {
        $this->table('users', function (Blueprint $table): void {
            $table->string('avatar_path', 255)->default('');
            $table->string('avatar_mime', 100)->default('');
        });

        if (!$this->pretending()) {
            $this->restoreExisting();
        }
    }

    /**
     * Уже загруженные фото — в attachments, до того как колонки пропадут:
     * иначе всё, что успели загрузить, потерялось бы вместе с колонками.
     */
    private function migrateExisting(): void
    {
        $users = $this->db()->select("SELECT id, avatar_path, avatar_mime FROM users WHERE avatar_path != ''");

        foreach ($users as $user) {
            $this->statement(
                'INSERT INTO attachments (entity, entity_id, path, name, mime, size, user_id, created_at, updated_at)
                 VALUES (:entity, :entity_id, :path, :name, :mime, 0, :user_id, :created_at, :updated_at)',
                [
                    'entity'     => 'avatar',
                    'entity_id'  => (string) $user['id'],
                    'path'       => (string) $user['avatar_path'],
                    'name'       => basename((string) $user['avatar_path']),
                    'mime'       => (string) $user['avatar_mime'],
                    'user_id'    => $user['id'],
                    'created_at' => $this->now(),
                    'updated_at' => $this->now(),
                ]
            );
        }
    }

    /**
     * Откат — последнее вложение с entity = avatar обратно в колонки.
     */
    private function restoreExisting(): void
    {
        $rows = $this->db()->select("SELECT entity_id, path, mime FROM attachments WHERE entity = 'avatar' ORDER BY id DESC");

        $seen = [];

        foreach ($rows as $row) {
            $userId = (string) $row['entity_id'];

            if (isset($seen[$userId])) {
                continue;
            }

            $seen[$userId] = true;

            $this->statement(
                'UPDATE users SET avatar_path = :path, avatar_mime = :mime WHERE id = :id',
                ['path' => $row['path'], 'mime' => $row['mime'], 'id' => (int) $userId]
            );
        }
    }
}
