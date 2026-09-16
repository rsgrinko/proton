<?php

declare(strict_types=1);

/**
 * Свои карточки на «Обзоре» панели.
 *
 * Карточки ядра (пользователи, очередь, состояние) объявлены в Widgets и
 * здесь не повторяются. Право пустой строкой показывает карточку всем, кто
 * дошёл до «Обзора».
 */

use App\Models\Note;
use Rsgrinko\Proton\Support\Widgets;

Widgets::register('notes', 'Заметок', 'notes.view', static function (): array {
    return ['value' => Note::query()->count(), 'route' => 'notes.index'];
});
