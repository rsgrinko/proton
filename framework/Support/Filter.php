<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Support;

/**
 * Описание одного фильтра списка: что спросить у человека и во что это
 * превратится в запросе. Сам по себе ничего не делает — набор таких описаний
 * разбирает и применяет {@see Filters}.
 */
final class Filter
{
    /** Подстрока по нескольким колонкам сразу */
    public const SEARCH = 'search';

    /** Выбор из готового списка значений */
    public const SELECT = 'select';

    /** Точное совпадение со строкой */
    public const TEXT = 'text';

    /** Да/нет — колонка 0 или 1 */
    public const FLAG = 'flag';

    /** Колонка заполнена или пуста — для read_at, deleted_at и им подобных */
    public const FILLED = 'filled';

    /** Диапазон дат: два параметра, {ключ}_from и {ключ}_to */
    public const DATES = 'dates';

    /**
     * @param array<int, string>    $columns колонки, по которым идёт поиск
     * @param array<string, string> $options значение => подпись для выпадающего списка
     */
    private function __construct(
        public readonly string $type,
        public readonly string $key,
        public readonly string $label,
        public readonly array $columns,
        public readonly array $options = [],
        public readonly string $placeholder = '',
    ) {
    }

    /**
     * Поиск подстроки. Колонок может быть несколько — они складываются через OR.
     *
     * @param array<int, string>|string $columns
     */
    public static function search(string $key, string $label, array|string $columns, string $placeholder = ''): self
    {
        return new self(self::SEARCH, $key, $label, (array) $columns, [], $placeholder);
    }

    /**
     * Выбор из списка: значение сверяется с ключами $options, чужое отбрасывается.
     *
     * @param array<string|int, string> $options
     */
    public static function select(string $key, string $label, array $options, string $column = ''): self
    {
        $strings = [];

        foreach ($options as $value => $title) {
            $strings[(string) $value] = $title;
        }

        return new self(self::SELECT, $key, $label, [$column !== '' ? $column : $key], $strings);
    }

    /**
     * Точное совпадение: раздел журнала, тип записи.
     */
    public static function text(string $key, string $label, string $column = '', string $placeholder = ''): self
    {
        return new self(self::TEXT, $key, $label, [$column !== '' ? $column : $key], [], $placeholder);
    }

    /**
     * Да/нет. Третьего значения нет: пустое — «неважно».
     */
    public static function flag(string $key, string $label, string $yes = 'да', string $no = 'нет', string $column = ''): self
    {
        return new self(self::FLAG, $key, $label, [$column !== '' ? $column : $key], ['1' => $yes, '0' => $no]);
    }

    /**
     * Заполнена ли колонка: «прочитано», «подтверждено», «удалено».
     */
    public static function filled(string $key, string $label, string $yes = 'да', string $no = 'нет', string $column = ''): self
    {
        return new self(self::FILLED, $key, $label, [$column !== '' ? $column : $key], ['1' => $yes, '0' => $no]);
    }

    /**
     * Диапазон дат по одной колонке: в адресе это два параметра — {ключ}_from
     * и {ключ}_to, чтобы обе половины ходили по ссылкам сами.
     */
    public static function dates(string $key, string $label, string $column = ''): self
    {
        return new self(self::DATES, $key, $label, [$column !== '' ? $column : $key]);
    }

    /**
     * Колонка, к которой привязан фильтр.
     */
    public function column(): string
    {
        return $this->columns[0] ?? $this->key;
    }

    /**
     * Параметры адреса, которые занимает фильтр.
     *
     * @return array<int, string>
     */
    public function keys(): array
    {
        if ($this->type === self::DATES) {
            return [$this->key . '_from', $this->key . '_to'];
        }

        return [$this->key];
    }

    /**
     * Правила проверки значений — по ним `Filters` отсеивает мусор из адреса.
     *
     * @return array<string, string>
     */
    public function rules(): array
    {
        return match ($this->type) {
            self::SELECT => [$this->key => 'nullable|in:' . implode(',', array_keys($this->options))],
            self::FLAG, self::FILLED => [$this->key => 'nullable|in:0,1'],
            self::DATES  => [$this->key . '_from' => 'nullable|date', $this->key . '_to' => 'nullable|date'],
            default      => [$this->key => 'nullable|max:190'],
        };
    }
}
