<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Models;

use Rsgrinko\Proton\Database\Model\Model;
use Rsgrinko\Proton\Support\Str;

/**
 * Своё поле профиля: заводится в панели, а не в коде. Появляется сразу у
 * каждого пользователя — в его собственной анкете (заполняет он сам) и на
 * карточке в админке (админ только смотрит). Значения — в UserFieldValue,
 * одна строка на пару «пользователь + поле»; удалили поле — удалились и они.
 *
 *     $field = new UserField();
 *     $field->forceFill(['field_key' => 'ym_id', 'label' => 'ИД Яндекс.Метрики', 'type' => UserField::STRING])->save();
 */
final class UserField extends Model
{
    protected static string $table = 'user_fields';

    protected array $fillable = ['field_key', 'label', 'type', 'description', 'sort'];

    protected array $casts = ['sort' => 'int'];

    public const STRING  = 'string';
    public const TEXT    = 'text';
    public const NUMBER  = 'number';
    public const BOOLEAN = 'boolean';
    public const URL     = 'url';
    public const DATE    = 'date';

    /**
     * Типы поля: от типа зависят и элемент формы, и проверка значения.
     *
     * @return array<string, string>
     */
    public static function types(): array
    {
        return [
            self::STRING  => 'строка',
            self::TEXT    => 'многострочный текст',
            self::NUMBER  => 'число',
            self::BOOLEAN => 'да/нет',
            self::URL     => 'ссылка',
            self::DATE    => 'дата',
        ];
    }

    /**
     * Все поля в порядке показа: свой порядок, потом по времени заведения.
     *
     * @return array<int, self>
     */
    public static function allOrdered(): array
    {
        /** @var array<int, self> $fields */
        $fields = self::query()->orderBy('sort')->orderBy('id')->get();

        return $fields;
    }

    /**
     * Ключ из подписи, если его не задали руками: латиница и цифры, читается
     * как имя переменной. Занятый ключ получает хвост, чтобы не столкнуться
     * с уже существующим полем.
     */
    public static function keyFrom(string $label, string $wanted = ''): string
    {
        $base = Str::slug($wanted !== '' ? $wanted : $label);
        $base = str_replace('-', '_', $base);
        $base = trim($base, '_');

        if ($base === '') {
            $base = 'field';
        }

        $key = $base;
        $i   = 1;

        while (self::query()->where('field_key', $key)->first() !== null) {
            $key = $base . '_' . (++$i);
        }

        return $key;
    }

    public function typeLabel(): string
    {
        return self::types()[(string) $this->type] ?? (string) $this->type;
    }
}
