<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Console;

/**
 * Одна команда консольной утилиты.
 *
 * Новая команда — новый класс в Console/Commands (или в App\Commands) и строка
 * в реестре Application. Аргументы и опции разбирает Application и передаёт
 * готовыми; печать — через line(), колонки — через pad().
 */
abstract class Command
{
    /** @var array<int, string> Позиционные аргументы */
    protected array $args = [];

    /** @var array<string, string> Опции вида --name=value */
    protected array $options = [];

    /** @var callable(string): void Куда печатать — подменяется в тестах */
    private $output;

    public function __construct()
    {
        $this->output = static function (string $line): void {
            echo $line . PHP_EOL;
        };
    }

    /**
     * Имя команды: 'queue:retry'.
     */
    abstract public function name(): string;

    /**
     * Строка для справки — что команда делает.
     */
    abstract public function description(): string;

    /**
     * Как её звать: 'queue:retry <id>'. По умолчанию — просто имя.
     */
    public function usage(): string
    {
        return $this->name();
    }

    /**
     * Выполняет команду и возвращает код выхода: 0 — всё хорошо.
     */
    abstract public function run(): int;

    /**
     * @param array<int, string>        $args
     * @param array<string, string>     $options
     * @param (callable(string): void)|null $output
     */
    public function withInput(array $args, array $options, ?callable $output = null): static
    {
        $this->args    = $args;
        $this->options = $options;

        if ($output !== null) {
            $this->output = $output;
        }

        return $this;
    }

    protected function line(string $text = ''): void
    {
        ($this->output)($text);
    }

    /**
     * Заметное сообщение об успехе и об ошибке — просто чтобы глаз цеплялся.
     */
    protected function ok(string $text): void
    {
        $this->line('  ' . $text);
    }

    protected function fail(string $text): void
    {
        $this->line('ОШИБКА: ' . $text);
    }

    /**
     * Колонка нужной ширины. str_pad считает байты, а в русских словах их вдвое
     * больше — выравнивание разъезжается.
     */
    protected function pad(string $text, int $width): string
    {
        $length = mb_strlen($text);

        return $length >= $width ? $text . ' ' : $text . str_repeat(' ', $width - $length);
    }

    /**
     * Таблица: заголовок и строки. Ширина колонок считается по содержимому.
     *
     * @param array<int, string>              $headers
     * @param array<int, array<int, string>>  $rows
     */
    protected function table(array $headers, array $rows): void
    {
        $widths = [];

        foreach ($headers as $index => $title) {
            $widths[$index] = mb_strlen($title);
        }

        foreach ($rows as $row) {
            foreach ($row as $index => $cell) {
                $widths[$index] = max($widths[$index] ?? 0, mb_strlen((string) $cell));
            }
        }

        $line = '';

        foreach ($headers as $index => $title) {
            $line .= $this->pad($title, $widths[$index] + 2);
        }

        $this->line(rtrim($line));

        foreach ($rows as $row) {
            $line = '';

            foreach ($row as $index => $cell) {
                $line .= $this->pad((string) $cell, $widths[$index] + 2);
            }

            $this->line(rtrim($line));
        }
    }

    protected function arg(int $index, string $default = ''): string
    {
        return $this->args[$index] ?? $default;
    }

    protected function option(string $name, ?string $default = null): ?string
    {
        return $this->options[$name] ?? $default;
    }

    protected function hasOption(string $name): bool
    {
        return isset($this->options[$name]);
    }

    /**
     * Спрашивает у человека. В неинтерактивном запуске возвращает значение
     * по умолчанию — команда не должна вешаться в cron.
     */
    protected function ask(string $question, string $default = ''): string
    {
        if (!stream_isatty(STDIN)) {
            return $default;
        }

        $this->line($question . ($default !== '' ? ' [' . $default . ']' : '') . ':');

        $answer = trim((string) fgets(STDIN));

        return $answer !== '' ? $answer : $default;
    }

    /**
     * Подтверждение опасного действия. Без --force в неинтерактивном запуске — «нет».
     */
    protected function confirm(string $question): bool
    {
        if ($this->hasOption('force')) {
            return true;
        }

        return in_array(strtolower($this->ask($question . ' (да/нет)', 'нет')), ['да', 'y', 'yes'], true);
    }
}
