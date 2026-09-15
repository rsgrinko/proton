<?php

declare(strict_types=1);

namespace App\Migrations;

use Rsgrinko\Proton\Database\Migration;
use Rsgrinko\Proton\Database\Schema\Blueprint;

/**
 * Приоритет у задач очереди и журнал входящих вебхуков.
 *
 * Приоритет нужен, когда в очереди лежит тысяча рассылок, а письмо со сбросом
 * пароля должно уйти сейчас: воркер берёт сначала то, у чего число больше.
 *
 * Входящие вебхуки — обратная сторона исходящих: чужая система стучится к нам,
 * и её посылки должны где-то оставаться, иначе разбираться с «мы отправили,
 * а вы не приняли» нечем.
 */
final class AddQueuePriority extends Migration
{
    public function up(): void
    {
        $this->table('jobs', function (Blueprint $table): void {
            $table->integer('priority')->default(0);
        });

        $this->create('incoming_hooks', function (Blueprint $table): void {
            $table->id();
            // Кто прислал: ключ источника из реестра
            $table->string('source', 64);
            $table->string('event', 191)->default('');
            $table->string('ip', 64)->default('');
            $table->string('status', 16)->default('received');
            $table->longText('payload')->nullable();
            $table->text('error')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->index('idx_incoming_source', 'source');
            $table->index('idx_incoming_created', 'created_at');
        });
    }

    public function down(): void
    {
        $this->drop('incoming_hooks');

        $this->table('jobs', function (Blueprint $table): void {
            $table->dropColumn('priority');
        });
    }
}
