<?php

declare(strict_types=1);

namespace App\Migrations;

use Rsgrinko\Proton\Database\Migration;
use Rsgrinko\Proton\Database\Schema\Blueprint;

/**
 * Мягкое удаление для пользователей, ролей и вебхуков.
 *
 * Раньше запись из панели удалялась насовсем: ошиблись — доставайте копию базы.
 * Теперь она помечается deleted_at, пропадает из списков и живёт в «Корзине»,
 * откуда её возвращают или добивают.
 *
 * Логин и почта остаются занятыми удалённым пользователем — это сделано
 * нарочно: пока человека можно вернуть, его имя не должен занять другой.
 */
final class AddSoftDeletes extends Migration
{
    /** @var array<int, string> */
    private const TABLES = ['users', 'roles', 'webhooks'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            $this->table($table, function (Blueprint $blueprint): void {
                $blueprint->softDeletes();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            $this->table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn('deleted_at');
            });
        }
    }
}
