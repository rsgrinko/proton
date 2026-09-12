<?php

declare(strict_types=1);

namespace App\Migrations;

use Rsgrinko\Proton\Database\Migration;
use Rsgrinko\Proton\Database\Schema\Blueprint;

/**
 * Одноразовые ссылки: подтверждение почты и сброс пароля.
 *
 * Сам токен в базе не лежит, только его хеш: утёкшая база не должна давать
 * возможность войти под чужим адресом.
 */
final class CreateAuthTokens extends Migration
{
    public function up(): void
    {
        $this->create('auth_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id');
            // verify — подтверждение почты, reset — сброс пароля
            $table->string('type', 16);
            $table->string('token_hash', 64);
            $table->dateTime('expires_at');
            $table->dateTime('used_at')->nullable();
            $table->string('ip', 64)->default('');
            $table->dateTime('created_at')->nullable();

            $table->index('idx_auth_tokens_hash', 'token_hash');
        });
    }

    public function down(): void
    {
        $this->drop('auth_tokens');
    }
}
