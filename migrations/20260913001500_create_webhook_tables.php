<?php

declare(strict_types=1);

namespace App\Migrations;

use Rsgrinko\Proton\Database\Migration;
use Rsgrinko\Proton\Database\Schema\Blueprint;

/**
 * Подписки на события и журнал посылок.
 */
final class CreateWebhookTables extends Migration
{
    public function up(): void
    {
        $this->create('webhooks', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 191)->default('');
            $table->string('url', 500);
            // Список событий JSON-строкой; «*» — все события реестра
            $table->text('events')->nullable();
            // Секрет подписи — шифрованным: им подписывается каждая посылка,
            // значит читать его придётся снова, хеш тут не подойдёт
            $table->string('secret', 255)->default('');
            $table->integer('active')->default(1);
            // Сколько неудач подряд: по ним подписка и отключается сама
            $table->integer('failures')->default(0);
            $table->integer('last_status')->default(0);
            $table->string('last_error', 500)->default('');
            $table->dateTime('last_sent_at')->nullable();
            $table->timestamps();

            $table->index('idx_webhooks_active', 'active');
        });

        $this->create('webhook_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('webhook_id');
            $table->string('event', 64);
            $table->longText('payload')->nullable();
            $table->string('status', 16)->default('pending');
            $table->integer('attempts')->default(0);
            $table->integer('response_status')->default(0);
            $table->text('response_body')->nullable();
            $table->string('error', 500)->default('');
            $table->integer('duration_ms')->default(0);
            $table->dateTime('created_at');
            $table->dateTime('finished_at')->nullable();

            // По этому индексу открывается карточка подписки
            $table->index('idx_deliveries_webhook', ['webhook_id', 'id']);
            $table->index('idx_deliveries_created', 'created_at');
        });
    }

    public function down(): void
    {
        $this->drop('webhook_deliveries');
        $this->drop('webhooks');
    }
}
