<?php

declare(strict_types=1);

namespace App\Migrations;

use Rsgrinko\Proton\Database\Migration;
use Rsgrinko\Proton\Database\Schema\Blueprint;

/**
 * Лента уведомлений пользователя.
 */
final class CreateNotifications extends Migration
{
    public function up(): void
    {
        $this->create('notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id');
            // Тип — короткий код вроде webhook.disabled: по нему видно, о чём речь,
            // и можно отличать уведомления друг от друга, не разбирая текст
            $table->string('type', 64)->default('');
            $table->string('title', 191);
            $table->text('body')->nullable();
            // Куда вести по нажатию; пусто — уведомление без перехода
            $table->string('url', 500)->default('');
            $table->dateTime('read_at')->nullable();
            $table->dateTime('created_at');

            // По этому индексу рисуется лента и считаются непрочитанные
            $table->index('idx_notifications_user', ['user_id', 'read_at']);
            $table->index('idx_notifications_created', 'created_at');
        });
    }

    public function down(): void
    {
        $this->drop('notifications');
    }
}
