<?php

declare(strict_types=1);

/**
 * Консольный REPL: php bin/proton tinker.
 *
 * Интерактивный ввод (readline/STDIN) тестом не гоняется — проверяется
 * неинтерактивный режим `--execute`, который использует тот же вычислитель строк.
 */

use Rsgrinko\Proton\Console\Commands\TinkerCommand;

/**
 * @return array{status: int, out: string}
 */
function runTinker(string $script): array
{
    $out = '';

    $status = (new TinkerCommand())->withInput([], ['execute' => $script], static function (string $line) use (&$out): void {
        $out .= $line . "\n";
    })->run();

    return ['status' => $status, 'out' => $out];
}

test('tinker: печатает результат выражения', function (): void {
    $result = runTinker('1 + 1');

    assertSame(0, $result['status']);
    assertContains('2', $result['out']);
});

test('tinker: переменные сохраняются между строками', function (): void {
    $result = runTinker("\$x = 21;\n\$x * 2;");

    assertSame(0, $result['status']);
    assertContains('42', $result['out']);
});

test('tinker: оператор без значения ничего не печатает', function (): void {
    $result = runTinker('$x = 5;');

    assertSame(0, $result['status']);
    assertContains('5', $result['out']);
});

test('tinker: ошибка в одной строке не прерывает остальные и даёт код 1', function (): void {
    $result = runTinker("1 / 0;\n2 + 2;");

    assertSame(1, $result['status']);
    assertContains('DivisionByZeroError', $result['out']);
    assertContains('4', $result['out']);
});

test('tinker: неизвестная переменная — понятная ошибка, а не падение теста', function (): void {
    $result = runTinker('$неизвестная->метод()');

    assertSame(1, $result['status']);
    assertContains('Error', $result['out']);
});

test('tinker: составной оператор (foreach) выполняется как statement', function (): void {
    $result = runTinker('foreach ([1, 2, 3] as $n) { echo $n; }');

    assertSame(0, $result['status']);
});
