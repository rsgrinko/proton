<?php

declare(strict_types=1);

namespace App\Migrations;

use Rsgrinko\Proton\Database\Migration;
use Rsgrinko\Proton\Database\Schema\Blueprint;

/**
 * Тема оформления — своя у каждого, не общая настройка приложения. По
 * умолчанию светлая: тёмную включают в профиле, а не угадывают по системной
 * настройке браузера — так дизайн панели не расходится с тем, что показали
 * при согласовании.
 */
final class AddUserTheme extends Migration
{
    public function up(): void
    {
        $this->table('users', function (Blueprint $table): void {
            $table->string('theme', 5)->default('light');
        });
    }

    public function down(): void
    {
        $this->table('users', function (Blueprint $table): void {
            $table->dropColumn('theme');
        });
    }
}
