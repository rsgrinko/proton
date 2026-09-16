<?php

declare(strict_types=1);

namespace App\Migrations;

use Rsgrinko\Proton\Database\Migration;
use Rsgrinko\Proton\Database\Schema\Blueprint;

/**
 * Профиль выполнения задачи: сколько времени заняла и сколько сходила в базу.
 *
 * Полные тексты запросов не хранятся — это были бы гигабайты на молчащей
 * очереди рассылок. Только счётчик и сумма: этого хватает, чтобы увидеть
 * задачу, которая внезапно стала делать втрое больше запросов, чем обычно.
 */
final class AddJobProfile extends Migration
{
    public function up(): void
    {
        $this->table('jobs', function (Blueprint $table): void {
            $table->integer('duration_ms')->nullable();
            $table->integer('queries_count')->nullable();
            $table->float('queries_ms')->nullable();
        });
    }

    public function down(): void
    {
        $this->table('jobs', function (Blueprint $table): void {
            $table->dropColumn('duration_ms');
            $table->dropColumn('queries_count');
            $table->dropColumn('queries_ms');
        });
    }
}
