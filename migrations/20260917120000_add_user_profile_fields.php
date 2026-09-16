<?php

declare(strict_types=1);

namespace App\Migrations;

use Rsgrinko\Proton\Database\Migration;
use Rsgrinko\Proton\Database\Schema\Blueprint;

/**
 * Необязательные поля профиля: ядру они не нужны, но почти любому приложению
 * на Proton рано или поздно понадобится телефон или подпись под именем —
 * заводить их тогда придётся той же миграцией, только на боевой базе.
 * Все поля пустые по умолчанию и ни на что не влияют, пока их не заполнили.
 */
final class AddUserProfileFields extends Migration
{
    public function up(): void
    {
        $this->table('users', function (Blueprint $table): void {
            $table->string('phone', 32)->default('');
            $table->string('website', 191)->default('');
            $table->string('position', 191)->default('');
            $table->string('location', 191)->default('');
            $table->string('bio', 500)->default('');
        });
    }

    public function down(): void
    {
        $this->table('users', function (Blueprint $table): void {
            $table->dropColumn('phone');
            $table->dropColumn('website');
            $table->dropColumn('position');
            $table->dropColumn('location');
            $table->dropColumn('bio');
        });
    }
}
