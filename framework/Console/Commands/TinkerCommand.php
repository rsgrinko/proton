<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Console\Commands;

use ErrorException;
use ParseError;
use Rsgrinko\Proton\Console\Command;
use Rsgrinko\Proton\Database\Model\Model;
use Rsgrinko\Proton\Support\Config;
use Throwable;

/**
 * Интерактивная консоль: код на php с доступом к моделям, конфигу и контейнеру,
 * без отдельного скрипта ради разовой проверки.
 *
 * Классы указываются с полным пространством имён (`\App\Models\Note::find(1)`) —
 * своей магии с автоимпортом нет: это на порядок проще и ничего не подменяет.
 *
 * `--execute` нужен и для одноразовых команд из скриптов, и для тестов: код можно
 * разбить на строки через `\n`, переменные между ними сохраняются, как в обычном
 * сеансе.
 */
final class TinkerCommand extends Command
{
    public function name(): string
    {
        return 'tinker';
    }

    public function description(): string
    {
        return 'интерактивная консоль: код на php, доступны модели и конфиг';
    }

    public function usage(): string
    {
        return 'tinker [--execute=код]';
    }

    public function run(): int
    {
        if ($this->hasOption('execute')) {
            return $this->runScript((string) $this->option('execute', ''));
        }

        $this->line((string) Config::get('app.name', 'Proton') . ' tinker');
        $this->line('Код на php, ";" в конце можно не ставить. Классы — с полным именем: \App\Models\...');
        $this->line('exit — выход, Ctrl+D — тоже выход.');

        $vars = [];

        while (true) {
            $line = $this->prompt('>>> ');

            if ($line === null) {
                $this->line('');

                break;
            }

            $trimmed = trim($line);

            if ($trimmed === 'exit' || $trimmed === 'quit') {
                break;
            }

            if ($trimmed === '') {
                continue;
            }

            [$vars, ] = $this->evalLine($line, $vars);
        }

        return 0;
    }

    /**
     * Неинтерактивный прогон: код построчно, переменные общие для всех строк.
     * Провал одной строки не прерывает остальные — так виднее, что сломалось.
     */
    private function runScript(string $script): int
    {
        $vars = [];
        $ok   = true;

        foreach (preg_split('/\r?\n/', $script) ?: [] as $line) {
            [$vars, $lineOk] = $this->evalLine($line, $vars);
            $ok              = $ok && $lineOk;
        }

        return $ok ? 0 : 1;
    }

    /**
     * Спрашивает строку кода. С readline — история и нормальная строка ввода,
     * без него (типовая сборка php для Windows) — просто печатаем подсказку
     * отдельной строкой и читаем как есть.
     */
    private function prompt(string $label): ?string
    {
        if (function_exists('readline')) {
            $line = readline($label);

            if ($line === false) {
                return null;
            }

            if (trim($line) !== '') {
                readline_add_history($line);
            }

            return $line;
        }

        fwrite(STDOUT, $label);

        $line = fgets(STDIN);

        return $line === false ? null : rtrim($line, "\r\n");
    }

    /**
     * Выполняет одну строку в общей с прошлыми строками "памяти" переменных.
     *
     * eval() держит переменные в области видимости места вызова, а не команды —
     * поэтому память передаётся вручную через extract()/get_defined_vars().
     * Имена с "__" внутри метода — служебные, реальную переменную с таким именем
     * пользователь потеряет между строками, это осознанный компромисс ради
     * простоты.
     *
     * @param array<string, mixed> $vars
     * @return array{0: array<string, mixed>, 1: bool}
     */
    private function evalLine(string $code, array $vars): array
    {
        $code = trim($code);

        if ($code === '') {
            return [$vars, true];
        }

        extract($vars, EXTR_SKIP);

        $__statement = rtrim($code, ';');

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            try {
                // Выражение без "return" — обычный случай, поддержим его первым
                $__result = eval('return ' . $__statement . ';');
            } catch (ParseError) {
                // Не выражение, а оператор (foreach, if, присваивание с точкой
                // с запятой внутри и т.п.) — выполняем как есть, без значения
                $__result = eval($__statement . ';');
            }
        } catch (Throwable $__e) {
            restore_error_handler();
            $this->fail(get_class($__e) . ': ' . $__e->getMessage());

            $__vars = get_defined_vars();
            unset($__vars['code'], $__vars['vars'], $__vars['__statement'], $__vars['__e'], $__vars['__vars']);

            return [$__vars, false];
        }

        restore_error_handler();

        if ($__result !== null) {
            $this->line($this->format($__result));
        }

        $__vars = get_defined_vars();
        unset($__vars['code'], $__vars['vars'], $__vars['__statement'], $__vars['__result'], $__vars['__vars']);

        return [$__vars, true];
    }

    private function format(mixed $value): string
    {
        if ($value instanceof Model) {
            return get_class($value) . ' ' . var_export($value->toArray(), true);
        }

        if (is_object($value)) {
            return get_class($value) . ' ' . print_r($value, true);
        }

        return var_export($value, true);
    }
}
