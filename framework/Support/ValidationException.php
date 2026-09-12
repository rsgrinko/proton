<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Support;

/**
 * Данные не прошли проверку. API отдаёт по такому исключению код 422,
 * формы панели — возвращают человека обратно с сообщениями.
 */
final class ValidationException extends ProtonException
{
    /** @var array<string, array<int, string>> Ошибки по полям */
    private array $errors;

    /**
     * @param array<string, array<int, string>> $errors
     */
    public function __construct(array $errors)
    {
        $this->errors = $errors;

        parent::__construct('Данные не прошли проверку', ['errors' => $errors], 422);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Все сообщения одним списком — так их удобно показать во флеше.
     *
     * @return array<int, string>
     */
    public function messages(): array
    {
        $messages = [];

        foreach ($this->errors as $field) {
            foreach ($field as $message) {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    /**
     * Первое сообщение — когда места на все нет.
     */
    public function first(): string
    {
        return $this->messages()[0] ?? 'Данные не прошли проверку';
    }
}
