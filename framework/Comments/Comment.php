<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Comments;

use Rsgrinko\Proton\Database\Connection;
use Rsgrinko\Proton\Database\Model\Model;
use Rsgrinko\Proton\Database\Model\Relations\BelongsTo;
use Rsgrinko\Proton\Models\User;

/**
 * Комментарий, оставленный к записи любого раздела.
 *
 *     Comment::add('note', $note->id(), $viewer->id(), 'Текст');
 *     Comment::of('note', $note->id());              // все комментарии записи, старые сверху
 *     Comment::removeAll('note', $note->id());        // запись удалили — комментарии не нужны
 *
 * Та же идея, что у вложений (`Files\Attachment`): раздел и номер записи —
 * обычные колонки, а не внешний ключ, поэтому комментарии заводятся под новый
 * раздел без миграции. Само добавление и удаление конкретных строк — забота
 * контроллера раздела: чужому право писать или стирать здесь не проверяется.
 */
final class Comment extends Model
{
    protected static string $table = 'comments';

    /** Только created_at — комментарий не правят, только пишут и стирают */
    protected bool $timestamps = false;

    protected array $casts = ['user_id' => 'int'];

    /**
     * Оставить комментарий.
     */
    public static function add(string $entity, int|string $entityId, int $userId, string $body): self
    {
        $comment = new self();

        $comment->forceFill([
            'entity'     => $entity,
            'entity_id'  => (string) $entityId,
            'user_id'    => $userId > 0 ? $userId : null,
            'body'       => trim($body),
            'created_at' => Connection::now(),
        ]);

        $comment->save();

        return $comment;
    }

    /**
     * Комментарии записи, старые сверху — обсуждение читается по порядку.
     *
     * @return array<int, self>
     */
    public static function of(string $entity, int|string $entityId): array
    {
        /** @var array<int, self> $found */
        $found = self::query()
            ->where('entity', $entity)
            ->where('entity_id', (string) $entityId)
            ->orderBy('id', 'asc')
            ->get();

        return $found;
    }

    /**
     * Сколько комментариев у записи — для счётчика в списке и заголовка.
     */
    public static function countOf(string $entity, int|string $entityId): int
    {
        return self::query()
            ->where('entity', $entity)
            ->where('entity_id', (string) $entityId)
            ->count();
    }

    /**
     * Убрать все комментарии записи: саму запись удалили насовсем — держать
     * обсуждение того, чего больше нет, незачем.
     */
    public static function removeAll(string $entity, int|string $entityId): int
    {
        return self::query()
            ->where('entity', $entity)
            ->where('entity_id', (string) $entityId)
            ->delete();
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
