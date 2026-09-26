<?php

declare(strict_types=1);

namespace App\Migrations;

use Rsgrinko\Proton\Database\Migration;
use Rsgrinko\Proton\Database\Schema\Blueprint;

/**
 * Приглашения: письмо со ссылкой, по которой человек заводит себе учётную
 * запись с заранее выбранной ролью. Работают и при закрытой регистрации.
 *
 * Как у auth_tokens, в базе только хеш токена. Принятые и отозванные строки
 * не удаляются — по ним видно, кто кого позвал.
 */
final class CreateInvitations extends Migration
{
    public function up(): void
    {
        $this->create('invitations', function (Blueprint $table): void {
            $table->id();
            $table->string('email', 191);
            $table->foreignId('role_id');
            $table->string('token_hash', 64);
            // Кто позвал и кто в итоге завёлся по ссылке
            $table->foreignId('invited_by')->nullable();
            $table->foreignId('user_id')->nullable();
            $table->dateTime('expires_at');
            $table->dateTime('accepted_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->timestamps();

            $table->index('idx_invitations_hash', 'token_hash');
            $table->index('idx_invitations_email', 'email');
        });
    }

    public function down(): void
    {
        $this->drop('invitations');
    }
}
