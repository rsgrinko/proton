<?php

declare(strict_types=1);

/**
 * Наблюдатели моделей — методы жизненного цикла в одном классе на модель,
 * вместо instanceof-проверок в общем слушателе шины (config/events.php).
 *
 * Событие model.* видит и слушатель на шине, и наблюдатель — они не мешают
 * друг другу. Разница в том, где удобнее держать логику: одна проверка на
 * чужую модель в общем файле — нормально, несколько — уже повод завести
 * наблюдателя. Подробности и полный пример — docs/DATABASE.md.
 */

use App\Models\Note;
use App\Observers\NoteObserver;
use Rsgrinko\Proton\Database\Model\Observers;

Observers::register(Note::class, NoteObserver::class);
