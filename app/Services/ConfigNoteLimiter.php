<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\NoteLimiter;
use App\Models\Note;
use Rsgrinko\Proton\Support\Config;

/**
 * Лимит из настройки notes.per_user (config/settings.php). 0 — без ограничения.
 *
 * Живой пример привязки интерфейса в config/services.php — раньше эта проверка
 * лежала прямо в NotesController::store() и другой её не сделать было нельзя.
 */
final class ConfigNoteLimiter implements NoteLimiter
{
    public function exceeded(int $userId): bool
    {
        $limit = (int) Config::get('notes.per_user', 0);

        if ($limit <= 0) {
            return false;
        }

        return Note::query()->where('user_id', $userId)->count() >= $limit;
    }
}
