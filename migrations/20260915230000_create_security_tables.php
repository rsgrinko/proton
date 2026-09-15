<?php

declare(strict_types=1);

namespace App\Migrations;

use Rsgrinko\Proton\Database\Migration;
use Rsgrinko\Proton\Database\Schema\Blueprint;

/**
 * Блокировки адресов и журнал подозрительных событий.
 *
 * Неудачные входы и отказы по правам раньше нигде не оставались: лимит по
 * частоте молча отсекал перебор, а разобраться, кто и откуда ломился, было
 * не по чему.
 */
final class CreateSecurityTables extends Migration
{
    public function up(): void
    {
        $this->create('blocked_ips', function (Blueprint $table): void {
            $table->id();
            $table->string('ip', 64);
            $table->string('reason', 191)->default('');
            // Пусто — блокировка без срока, снимается только руками
            $table->dateTime('until')->nullable();
            $table->foreignId('created_by')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique('idx_blocked_ip', 'ip');
            $table->index('idx_blocked_until', 'until');
        });

        $this->create('security_events', function (Blueprint $table): void {
            $table->id();
            // login.failed, login.blocked, access.denied, key.invalid
            $table->string('kind', 32);
            $table->string('ip', 64)->default('');
            $table->string('login', 191)->default('');
            $table->string('path', 191)->default('');
            $table->string('agent', 255)->default('');
            $table->dateTime('created_at')->nullable();

            $table->index('idx_security_kind', 'kind');
            $table->index('idx_security_ip', 'ip');
            $table->index('idx_security_created', 'created_at');
        });
    }

    public function down(): void
    {
        foreach (['security_events', 'blocked_ips'] as $table) {
            $this->drop($table);
        }
    }
}
