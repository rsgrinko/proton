<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Support;

use Rsgrinko\Proton\Database\Connection;

/**
 * Проверка входных данных по правилам.
 *
 *     $data = Validator::make($request->all(), [
 *         'email'    => 'required|email|unique:users,email',
 *         'password' => 'required|min:6|confirmed',
 *         'age'      => 'nullable|integer|between:1,150',
 *     ])->validate();
 *
 * Правила перечисляются строкой через «|», аргументы — через двоеточие.
 * Ошибки собираются по всем полям сразу: человек должен увидеть все проблемы
 * формы за один раз, а не по одной на каждую отправку.
 *
 * validate() возвращает только проверенные поля — то, чего не было в правилах,
 * в результат не попадёт, и лишнее из формы не уедет в базу.
 */
final class Validator
{
    /** @var array<string, mixed> */
    private array $data;

    /** @var array<string, string> Поле => строка правил */
    private array $rules;

    /** @var array<string, string> Свои подписи полей для сообщений */
    private array $labels;

    /** @var array<string, array<int, string>> */
    private array $errors = [];

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $rules
     * @param array<string, string> $labels
     */
    private function __construct(array $data, array $rules, array $labels)
    {
        $this->data   = $data;
        $this->rules  = $rules;
        $this->labels = $labels;
    }

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $rules
     * @param array<string, string> $labels подписи полей: ['email' => 'Почта']
     */
    public static function make(array $data, array $rules, array $labels = []): self
    {
        return new self($data, $rules, $labels);
    }

    /**
     * Быстрая проверка адреса — нужна много где помимо форм.
     */
    public static function isEmail(string $email): bool
    {
        $email = trim($email);

        if ($email === '' || strlen($email) > 254) {
            return false;
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        $domain = substr(strrchr($email, '@') ?: '', 1);

        return $domain !== '' && str_contains($domain, '.');
    }

    /**
     * Проверяет данные и возвращает только проверенные поля.
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function validate(): array
    {
        $clean = [];

        foreach ($this->rules as $field => $rules) {
            $value = $this->value($field);
            $list  = array_filter(explode('|', $rules));

            $nullable = in_array('nullable', $list, true);
            $required = in_array('required', $list, true);
            $empty    = $value === null || $value === '' || $value === [];

            if ($empty) {
                if ($required) {
                    $this->fail($field, 'Поле «' . $this->label($field) . '» обязательно');

                    continue;
                }

                // Незаполненное необязательное поле дальше не проверяем: правило
                // «email» не должно ругаться на пустую строку
                //
                // Галочка — особый случай: браузер не присылает её вовсе, когда
                // она снята, и это значит «нет», а не «неизвестно». Иначе поле
                // уезжало бы в базу как NULL и падало на NOT NULL
                if (in_array('boolean', $list, true)) {
                    $clean[$field] = 0;

                    continue;
                }

                $clean[$field] = $nullable ? null : $value;

                continue;
            }

            foreach ($list as $rule) {
                [$name, $argument] = array_pad(explode(':', $rule, 2), 2, '');

                if (in_array($name, ['required', 'nullable'], true)) {
                    continue;
                }

                $this->apply($field, $name, (string) $argument, $value);
            }

            $clean[$field] = $this->cast($value, $list);
        }

        if ($this->errors !== []) {
            throw new ValidationException($this->errors);
        }

        return $clean;
    }

    /**
     * Проверка без исключения: true — данные в порядке.
     */
    public function passes(): bool
    {
        try {
            $this->validate();

            return true;
        } catch (ValidationException) {
            return false;
        }
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Применяет одно правило к значению.
     */
    private function apply(string $field, string $rule, string $argument, mixed $value): void
    {
        $label = $this->label($field);
        $text  = is_scalar($value) ? (string) $value : '';

        switch ($rule) {
            case 'email':
                if (!self::isEmail($text)) {
                    $this->fail($field, 'Поле «' . $label . '» должно быть адресом почты');
                }

                break;

            case 'integer':
                if (preg_match('/^-?\d+$/', $text) !== 1) {
                    $this->fail($field, 'Поле «' . $label . '» должно быть целым числом');
                }

                break;

            case 'numeric':
                if (!is_numeric($text)) {
                    $this->fail($field, 'Поле «' . $label . '» должно быть числом');
                }

                break;

            case 'boolean':
                if (!in_array($text, ['0', '1', 'true', 'false', 'on', 'off'], true)) {
                    $this->fail($field, 'Поле «' . $label . '» должно быть да или нет');
                }

                break;

            case 'min':
                if (mb_strlen($text) < (int) $argument) {
                    $this->fail($field, 'Поле «' . $label . '» короче ' . (int) $argument . ' символов');
                }

                break;

            case 'max':
                if (mb_strlen($text) > (int) $argument) {
                    $this->fail($field, 'Поле «' . $label . '» длиннее ' . (int) $argument . ' символов');
                }

                break;

            case 'between':
                [$from, $to] = array_pad(explode(',', $argument), 2, '0');

                if ((float) $text < (float) $from || (float) $text > (float) $to) {
                    $this->fail($field, 'Поле «' . $label . '» должно быть от ' . $from . ' до ' . $to);
                }

                break;

            case 'in':
                if (!in_array($text, explode(',', $argument), true)) {
                    $this->fail($field, 'Недопустимое значение поля «' . $label . '»');
                }

                break;

            case 'regex':
                if (@preg_match($argument, $text) !== 1) {
                    $this->fail($field, 'Поле «' . $label . '» заполнено неверно');
                }

                break;

            case 'url':
                if (filter_var($text, FILTER_VALIDATE_URL) === false) {
                    $this->fail($field, 'Поле «' . $label . '» должно быть ссылкой');
                }

                break;

            case 'date':
                if (strtotime($text) === false) {
                    $this->fail($field, 'Поле «' . $label . '» должно быть датой');
                }

                break;

            case 'same':
                if ($text !== (string) $this->value($argument)) {
                    $this->fail($field, 'Поля «' . $label . '» и «' . $this->label($argument) . '» не совпадают');
                }

                break;

            case 'confirmed':
                if ($text !== (string) $this->value($field . '_confirmation')) {
                    $this->fail($field, 'Поле «' . $label . '» не совпадает с повтором');
                }

                break;

            case 'unique':
                $this->checkUnique($field, $argument, $text);

                break;

            case 'exists':
                $this->checkExists($field, $argument, $text);

                break;
        }
    }

    /**
     * unique:таблица,колонка[,исключить_id[,колонка_ключа]] — правка своей же
     * записи не должна спотыкаться о собственное значение.
     */
    private function checkUnique(string $field, string $argument, string $value): void
    {
        [$table, $column, $ignore, $key] = array_pad(explode(',', $argument), 4, '');

        $column = $column !== '' ? $column : $field;
        $key    = $key !== '' ? $key : 'id';

        if (!$this->safeName($table) || !$this->safeName($column) || !$this->safeName($key)) {
            throw new ProtonException('Правило unique задано неверно: ' . $argument);
        }

        $sql    = 'SELECT ' . $key . ' FROM ' . $table . ' WHERE ' . $column . ' = :value';
        $params = ['value' => $value];

        if ($ignore !== '' && $ignore !== '0') {
            $sql .= ' AND ' . $key . ' <> :ignore';

            $params['ignore'] = $ignore;
        }

        if (Connection::instance()->selectOne($sql . ' LIMIT 1', $params) !== null) {
            $this->fail($field, 'Такое значение поля «' . $this->label($field) . '» уже есть');
        }
    }

    /**
     * exists:таблица[,колонка] — ссылка на существующую запись.
     */
    private function checkExists(string $field, string $argument, string $value): void
    {
        [$table, $column] = array_pad(explode(',', $argument), 2, '');

        $column = $column !== '' ? $column : 'id';

        if (!$this->safeName($table) || !$this->safeName($column)) {
            throw new ProtonException('Правило exists задано неверно: ' . $argument);
        }

        $row = Connection::instance()->selectOne(
            'SELECT ' . $column . ' FROM ' . $table . ' WHERE ' . $column . ' = :value LIMIT 1',
            ['value' => $value]
        );

        if ($row === null) {
            $this->fail($field, 'Запись для поля «' . $this->label($field) . '» не найдена');
        }
    }

    /**
     * Приводит значение к типу, который обещало правило: в базу должно уехать
     * число, а не строка из формы.
     *
     * @param array<int, string> $rules
     */
    private function cast(mixed $value, array $rules): mixed
    {
        if (in_array('integer', $rules, true)) {
            return (int) $value;
        }

        if (in_array('numeric', $rules, true)) {
            return (float) $value;
        }

        if (in_array('boolean', $rules, true)) {
            return in_array((string) $value, ['1', 'true', 'on'], true) ? 1 : 0;
        }

        return is_string($value) ? trim($value) : $value;
    }

    private function value(string $field): mixed
    {
        return $this->data[$field] ?? null;
    }

    private function label(string $field): string
    {
        return $this->labels[$field] ?? $field;
    }

    private function fail(string $field, string $message): void
    {
        $this->errors[$field][] = $message;
    }

    /**
     * Имена таблиц и колонок в SQL параметром не подставить, поэтому сверяем их сами.
     */
    private function safeName(string $name): bool
    {
        return preg_match('/^[A-Za-z0-9_]+$/', $name) === 1;
    }
}
