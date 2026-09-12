<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Console\Commands;

use Rsgrinko\Proton\Console\Command;
use Rsgrinko\Proton\Support\Str;

/**
 * Общая часть генераторов: взять заготовку из stubs/, подставить имя, положить
 * файл на место. Сами генераторы отличаются только заготовкой и каталогом.
 *
 * Существующий файл не перезаписываем никогда: генератор не должен затирать код.
 */
abstract class MakeCommand extends Command
{
    /**
     * Имя файла заготовки в каталоге stubs/.
     */
    abstract protected function stub(): string;

    /**
     * Каталог, куда класть результат, — от корня проекта.
     */
    abstract protected function directory(): string;

    /**
     * Что за штука создаётся — для сообщения.
     */
    abstract protected function what(): string;

    /**
     * Суффикс имени класса: Controller, Job, Test.
     */
    protected function suffix(): string
    {
        return '';
    }

    public function run(): int
    {
        $raw = trim($this->arg(0));

        if ($raw === '') {
            $this->fail('Укажите имя: php bin/proton ' . $this->name() . ' Имя');

            return 1;
        }

        $class = Str::studly($raw);
        $suffix = $this->suffix();

        if ($suffix !== '' && !str_ends_with($class, $suffix)) {
            $class .= $suffix;
        }

        $path = APP_ROOT . '/' . trim($this->directory(), '/') . '/' . $class . '.php';

        if (is_file($path)) {
            // Существующий файл не трогаем никогда: генератор не должен затирать код
            $this->fail('Уже есть — ' . $this->what() . ': ' . $this->relative($path));

            return 1;
        }

        $content = $this->render($class);

        $dir = dirname($path);

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            $this->fail('Не удалось создать каталог ' . $dir);

            return 1;
        }

        if (@file_put_contents($path, $content) === false) {
            $this->fail('Не удалось записать файл ' . $path);

            return 1;
        }

        $this->ok('Создано — ' . $this->what() . ': ' . $this->relative($path));

        $this->after($class, $path);

        return 0;
    }

    /**
     * Что напечатать после создания — подсказка про реестр, миграцию и прочее.
     */
    protected function after(string $class, string $path): void
    {
    }

    /**
     * Подстановки в заготовке: {{class}}, {{table}} и так далее.
     *
     * @return array<string, string>
     */
    protected function replacements(string $class): array
    {
        return [
            '{{class}}' => $class,
            '{{table}}' => Str::plural(Str::snake($class)),
            '{{slug}}'  => Str::snake($class),
        ];
    }

    private function render(string $class): string
    {
        $stub = APP_ROOT . '/stubs/' . $this->stub();

        if (!is_file($stub)) {
            return "<?php\n\ndeclare(strict_types=1);\n";
        }

        return strtr((string) file_get_contents($stub), $this->replacements($class));
    }

    protected function relative(string $path): string
    {
        return str_replace(APP_ROOT . '/', '', str_replace('\\', '/', $path));
    }
}
