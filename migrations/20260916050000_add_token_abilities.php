<?php

declare(strict_types=1);

namespace App\Migrations;

use Rsgrinko\Proton\Database\Migration;
use Rsgrinko\Proton\Database\Schema\Blueprint;

/**
 * Скоуп ключа API: список прав через запятую, которыми ключ ограничен —
 * даже если у владельца их больше. Пусто — без ограничения, ключ может
 * всё, что может владелец (как раньше).
 */
final class AddTokenAbilities extends Migration
{
    public function up(): void
    {
        $this->table('api_tokens', function (Blueprint $table): void {
            $table->string('abilities', 500)->default('');
        });
    }

    public function down(): void
    {
        $this->table('api_tokens', function (Blueprint $table): void {
            $table->dropColumn('abilities');
        });
    }
}
