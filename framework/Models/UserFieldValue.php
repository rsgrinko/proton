<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Models;

use Rsgrinko\Proton\Database\Model\Model;

/**
 * Значение своего поля профиля у конкретного пользователя. Строка заводится,
 * только когда поле заполнили, — необязательное поле без значения строки
 * не занимает вовсе.
 */
final class UserFieldValue extends Model
{
    protected static string $table = 'user_field_values';

    protected array $fillable = ['user_id', 'field_id', 'value'];

    protected array $casts = ['user_id' => 'int', 'field_id' => 'int'];

    /**
     * Значения пользователя по всем его полям: id поля => значение.
     *
     * @return array<int, string>
     */
    public static function valuesFor(int $userId): array
    {
        $values = [];

        /** @var array<int, self> $rows */
        $rows = self::query()->where('user_id', $userId)->get();

        foreach ($rows as $row) {
            $values[(int) $row->raw('field_id')] = (string) $row->value;
        }

        return $values;
    }

    /**
     * Ставит значение поля. Пустое значение убирает строку совсем — строка
     * есть только там, где поле действительно заполнили.
     */
    public static function put(int $userId, int $fieldId, string $value): void
    {
        /** @var self|null $row */
        $row = self::query()->where('user_id', $userId)->where('field_id', $fieldId)->first();

        if (trim($value) === '') {
            $row?->delete();

            return;
        }

        if ($row === null) {
            $row = new self();
            $row->forceFill(['user_id' => $userId, 'field_id' => $fieldId]);
        }

        $row->forceFill(['value' => $value])->save();
    }

    /**
     * Убирает все значения поля разом — зовётся перед удалением самого поля.
     */
    public static function forgetField(int $fieldId): void
    {
        self::query()->where('field_id', $fieldId)->delete();
    }
}
