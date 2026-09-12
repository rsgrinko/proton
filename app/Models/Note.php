<?php

declare(strict_types=1);

namespace App\Models;

use Rsgrinko\Proton\Database\Model\Model;
use Rsgrinko\Proton\Database\Model\Relations\BelongsTo;
use Rsgrinko\Proton\Models\User;
use Rsgrinko\Proton\Support\Str;

/**
 * Заметка — демонстрационная модель. Показывает всё сразу: массовое заполнение
 * по белому списку, приведение типов, связь с пользователем, мягкое удаление
 * и вложенный файл.
 *
 * Удаляется вместе со всем разделом заметок, если он не нужен.
 */
final class Note extends Model
{
    protected static string $table = 'notes';

    /** Массово заполняем только это: остальное ставит код, а не форма */
    protected array $fillable = ['title', 'body', 'pinned'];

    protected array $casts = [
        'pinned'  => 'bool',
        'user_id' => 'int',
    ];

    /** Заметка не пропадает совсем: помечается deleted_at и уходит из списков */
    protected bool $softDelete = true;

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Адресная часть считается сама — по названию.
     */
    public function save(): bool
    {
        if (trim((string) $this->raw('slug')) === '') {
            $this->setAttribute('slug', Str::slug((string) $this->raw('title')));
        }

        return parent::save();
    }

    public function hasFile(): bool
    {
        return trim((string) $this->raw('file_path')) !== '';
    }

    /**
     * Короткий пересказ для списка.
     */
    public function excerpt(int $length = 160): string
    {
        return Str::limit(trim(strip_tags((string) $this->raw('body'))), $length);
    }
}
