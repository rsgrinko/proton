<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Console\Commands;

use Rsgrinko\Proton\Console\Command;
use Rsgrinko\Proton\Support\Str;

/**
 * Раздел целиком: модель, миграция, контроллер, три вьюхи и тест.
 *
 * Остальные make-команды делают по одному файлу — здесь их шесть, и все они
 * должны сойтись друг с другом: имена полей в форме, правила проверки, колонки
 * таблицы и подстановки во вьюхах. Руками это собирается полчаса, и каждый раз
 * что-нибудь забывается.
 *
 *     php bin/proton make:crud Order --fields="title:string:Название,total:decimal:Сумма,paid:bool:Оплачен"
 *
 * Поле описывается как имя:тип[:подпись]. Типы: string, text, int, decimal,
 * bool, date, datetime, email. Подпись — то, что увидит человек в форме и в
 * сообщении об ошибке; без неё берётся имя поля.
 *
 * Флаг --api добавляет седьмой файл — контроллер в app/Controllers/Api/,
 * тот же раздел, только под ключ вместо сеанса: список со страницами и
 * поиском, карточка, создание, правка (PATCH меняет только присланные поля)
 * и удаление. Формат ответа общий для всего API — success: {"data": …},
 * ошибка: {"error": …}, дальше разбираться не нужно.
 *
 * Маршруты, право и пункт меню команда не дописывает, а печатает готовыми
 * кусками: routes/web.php (и routes/api.php с --api) и config/menu.php —
 * код приложения, и лезть в них автоматической правкой опаснее, чем
 * скопировать несколько строк.
 */
final class MakeCrudCommand extends Command
{
    /** Типы полей: как их писать в миграции, чем проверять, во что приводить */
    private const TYPES = [
        'string'   => ['schema' => "string('%s')", 'rule' => 'nullable|max:191', 'cast' => '', 'input' => 'text'],
        'text'     => ['schema' => "text('%s')", 'rule' => 'nullable|max:20000', 'cast' => '', 'input' => 'textarea'],
        'int'      => ['schema' => "integer('%s')", 'rule' => 'nullable|integer', 'cast' => 'int', 'input' => 'number'],
        'decimal'  => ['schema' => "decimal('%s')", 'rule' => 'nullable|numeric', 'cast' => 'float', 'input' => 'decimal'],
        'bool'     => ['schema' => "boolean('%s')", 'rule' => 'nullable|boolean', 'cast' => 'bool', 'input' => 'checkbox'],
        'date'     => ['schema' => "date('%s')", 'rule' => 'nullable|date', 'cast' => '', 'input' => 'date'],
        'datetime' => ['schema' => "dateTime('%s')", 'rule' => 'nullable|date', 'cast' => '', 'input' => 'datetime-local'],
        'email'    => ['schema' => "string('%s')", 'rule' => 'nullable|email|max:191', 'cast' => '', 'input' => 'email'],
    ];

    public function name(): string
    {
        return 'make:crud';
    }

    public function description(): string
    {
        return 'создать раздел целиком: модель, миграция, контроллер, вьюхи, тест';
    }

    public function usage(): string
    {
        return 'make:crud <Имя> [--fields="имя:тип:подпись,…"] [--no-soft-delete] [--api] [--force]';
    }

    public function run(): int
    {
        $raw = trim($this->arg(0));

        if ($raw === '') {
            $this->fail('Укажите имя раздела: php bin/proton make:crud Order');

            return 1;
        }

        $class = Str::studly($raw);
        $fields = $this->fields();

        if ($fields === []) {
            $this->fail('Не разобрал ни одного поля. Пример: --fields="title:string:Название,total:decimal:Сумма"');

            return 1;
        }

        $names = $this->names($class);
        $plan  = $this->plan($names, $fields);

        // Сначала смотрим все файлы разом: раздел, собранный наполовину,
        // хуже, чем не собранный вовсе
        $existing = [];

        foreach ($plan as $path => $content) {
            if (is_file($path)) {
                $existing[] = $this->relative($path);
            }
        }

        if ($existing !== [] && !$this->hasOption('force')) {
            $this->fail('Уже есть: ' . implode(', ', $existing));
            $this->line('  Сначала уберите их или дайте другое имя (--force перезапишет).');

            return 1;
        }

        foreach ($plan as $path => $content) {
            $dir = dirname($path);

            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                $this->fail('Не удалось создать каталог ' . $dir);

                return 1;
            }

            if (@file_put_contents($path, $content) === false) {
                $this->fail('Не удалось записать файл ' . $path);

                return 1;
            }

            $this->ok('Создано: ' . $this->relative($path));
        }

        $this->hints($names, $fields);

        return 0;
    }

    /**
     * Имена, которые понадобятся во всех заготовках сразу.
     *
     * @return array<string, string>
     */
    private function names(string $class): array
    {
        $snake  = Str::snake($class);
        $plural = Str::plural($snake);

        return [
            'class'    => $class,
            'variable' => lcfirst($class),
            'table'    => $plural,
            'route'    => str_replace('_', '-', $plural),
            'views'    => $plural,
            'title'    => $this->option('title', $class) ?? $class,
        ];
    }

    /**
     * Разбирает --fields в список полей.
     *
     * @return array<int, array{name: string, type: string, label: string}>
     */
    private function fields(): array
    {
        $raw = trim((string) $this->option('fields', ''));

        if ($raw === '') {
            // Без описания полей раздел всё равно должен получиться рабочим
            $raw = 'title:string:Название,body:text:Текст';
        }

        $fields = [];

        foreach (explode(',', $raw) as $chunk) {
            $parts = array_map('trim', explode(':', trim($chunk)));
            $name  = Str::snake((string) ($parts[0] ?? ''));

            if ($name === '' || !preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
                continue;
            }

            $type = strtolower((string) ($parts[1] ?? 'string'));

            if (!isset(self::TYPES[$type])) {
                $this->line('  Тип «' . $type . '» не знаю, беру string для поля ' . $name);

                $type = 'string';
            }

            $fields[] = [
                'name'  => $name,
                'type'  => $type,
                'label' => ($parts[2] ?? '') !== '' ? (string) $parts[2] : $name,
            ];
        }

        return $fields;
    }

    /**
     * Что и куда записать: путь файла -> его содержимое.
     *
     * @param array<string, string>                                        $names
     * @param array<int, array{name: string, type: string, label: string}> $fields
     *
     * @return array<string, string>
     */
    private function plan(array $names, array $fields): array
    {
        $soft = !$this->hasOption('no-soft-delete');

        $replacements = [
            '{{class}}'     => $names['class'],
            '{{plural}}'    => Str::plural($names['class']),
            '{{variable}}'  => $names['variable'],
            '{{table}}'     => $names['table'],
            '{{route}}'     => $names['route'],
            '{{views}}'     => $names['views'],
            '{{title}}'     => $names['title'],
            '{{fillable}}'  => $this->fillable($fields),
            '{{casts}}'     => $this->casts($fields),
            '{{soft}}'      => $soft ? "\n    /** Запись не пропадает совсем: помечается deleted_at и уходит из списков */\n    protected bool \$softDelete = true;\n" : '',
            '{{softSchema}}' => $soft ? "\n            \$table->softDeletes();" : '',
            '{{schema}}'    => $this->schema($fields),
            '{{rules}}'     => $this->rules($fields),
            '{{updateRules}}' => $this->updateRules($fields),
            '{{labels}}'    => $this->labels($fields),
            '{{before}}'    => $this->before($fields),
            '{{after}}'     => $this->after($fields),
            '{{form}}'      => $this->form($fields),
            '{{heads}}'     => $this->columns($fields)['heads'],
            '{{cells}}'     => $this->columns($fields)['cells'],
            '{{props}}'     => $this->props($fields),
            '{{first}}'     => $fields[0]['name'],
            '{{firstLabel}}' => $fields[0]['label'],
            '{{testValue}}' => $this->testValue($fields[0]),
        ];

        $root = APP_ROOT;

        $plan = [
            $root . '/app/Models/' . $names['class'] . '.php'                         => $this->render('crud/model.stub', $replacements),
            $root . '/app/Controllers/Web/' . Str::plural($names['class']) . 'Controller.php' => $this->render('crud/controller.stub', $replacements),
            $root . '/migrations/' . date('YmdHis') . '_create_' . $names['table'] . '.php'   => $this->render('crud/migration.stub', $replacements),
            $root . '/resources/views/' . $names['views'] . '/index.php'              => $this->render('crud/index.stub', $replacements),
            $root . '/resources/views/' . $names['views'] . '/form.php'               => $this->render('crud/form.stub', $replacements),
            $root . '/resources/views/' . $names['views'] . '/show.php'               => $this->render('crud/show.stub', $replacements),
            $root . '/tests/' . Str::plural($names['class']) . 'Test.php'             => $this->render('crud/test.stub', $replacements),
        ];

        if ($this->hasOption('api')) {
            $plan[$root . '/app/Controllers/Api/' . Str::plural($names['class']) . 'Controller.php']
                = $this->render('crud/api-controller.stub', $replacements);
        }

        return $plan;
    }

    /**
     * @param array<int, array{name: string, type: string, label: string}> $fields
     */
    private function fillable(array $fields): string
    {
        return implode(', ', array_map(static fn (array $f): string => "'" . $f['name'] . "'", $fields));
    }

    /**
     * @param array<int, array{name: string, type: string, label: string}> $fields
     */
    private function casts(array $fields): string
    {
        $lines = [];

        $width = max($this->width($fields), mb_strlen('user_id'));

        foreach ($fields as $field) {
            $cast = self::TYPES[$field['type']]['cast'];

            if ($cast !== '') {
                $pad = str_repeat(' ', $width - mb_strlen($field['name']));

                $lines[] = "        '" . $field['name'] . "'" . $pad . " => '" . $cast . "',";
            }
        }

        $lines[] = "        'user_id'" . str_repeat(' ', $width - mb_strlen('user_id')) . " => 'int',";

        return implode("\n", $lines);
    }

    /**
     * @param array<int, array{name: string, type: string, label: string}> $fields
     */
    private function schema(array $fields): string
    {
        $lines = [];

        foreach ($fields as $field) {
            $call = sprintf(self::TYPES[$field['type']]['schema'], $field['name']);

            $lines[] = '            $table->' . $call . ($field['type'] === 'bool' ? '->default(0)' : '->nullable()') . ';';
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<int, array{name: string, type: string, label: string}> $fields
     */
    private function rules(array $fields): string
    {
        $lines = [];
        $width = $this->width($fields);

        foreach ($fields as $index => $field) {
            // Первое поле — название раздела, без него запись не опознать
            $rule = $index === 0 && $field['type'] === 'string' ? 'required|max:191' : self::TYPES[$field['type']]['rule'];

            $lines[] = "            '" . $field['name'] . "'" . str_repeat(' ', $width - mb_strlen($field['name'])) . " => '" . $rule . "',";
        }

        return implode("\n", $lines);
    }

    /**
     * Правила для правки через API: PATCH меняет то, что прислали, поэтому
     * обязательных полей нет вовсе — даже у первого.
     *
     * @param array<int, array{name: string, type: string, label: string}> $fields
     */
    private function updateRules(array $fields): string
    {
        $lines = [];
        $width = $this->width($fields);

        foreach ($fields as $field) {
            $rule = self::TYPES[$field['type']]['rule'];

            $lines[] = "            '" . $field['name'] . "'" . str_repeat(' ', $width - mb_strlen($field['name'])) . " => '" . $rule . "',";
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<int, array{name: string, type: string, label: string}> $fields
     */
    private function labels(array $fields): string
    {
        $lines = [];
        $width = $this->width($fields);

        foreach ($fields as $field) {
            $lines[] = "            '" . $field['name'] . "'" . str_repeat(' ', $width - mb_strlen($field['name'])) . " => '" . $field['label'] . "',";
        }

        return implode("\n", $lines);
    }

    /**
     * Снимок значений до правки — для журнала изменений.
     *
     * @param array<int, array{name: string, type: string, label: string}> $fields
     */
    private function before(array $fields): string
    {
        $parts = array_map(
            static fn (array $f): string => "'" . $f['name'] . "' => \$record->raw('" . $f['name'] . "')",
            $fields
        );

        return implode(', ', $parts);
    }

    /**
     * @param array<int, array{name: string, type: string, label: string}> $fields
     */
    private function after(array $fields): string
    {
        $lines = [];

        foreach ($fields as $field) {
            $lines[] = "            '" . $field['name'] . "' => \$record->raw('" . $field['name'] . "'),";
        }

        return implode("\n", $lines);
    }

    /**
     * Поля формы: у каждого типа свой вид ввода.
     *
     * @param array<int, array{name: string, type: string, label: string}> $fields
     */
    private function form(array $fields): string
    {
        $blocks = [];

        foreach ($fields as $index => $field) {
            $name  = $field['name'];
            $label = $field['label'];
            $input = self::TYPES[$field['type']]['input'];
            $auto  = $index === 0 ? ' autofocus' : '';
            $need  = $index === 0 && $field['type'] === 'string' ? ' required' : '';

            if ($input === 'textarea') {
                $blocks[] = <<<HTML
        <label>
            <span>{$label}</span>
            <textarea name="{$name}"><?= View::e((string) \$record->{$name}) ?></textarea>
        </label>
HTML;

                continue;
            }

            if ($input === 'checkbox') {
                $blocks[] = <<<HTML
        <label class="inline" style="margin-bottom: 12px;">
            <input type="checkbox" name="{$name}" value="1" <?= \$record->{$name} ? 'checked' : '' ?>>
            <span>{$label}</span>
        </label>
HTML;

                continue;
            }

            $type = $input === 'decimal' ? 'number" step="0.01' : $input;

            $blocks[] = <<<HTML
        <label>
            <span>{$label}</span>
            <input type="{$type}" name="{$name}" value="<?= View::e((string) \$record->raw('{$name}')) ?>"{$auto}{$need}>
        </label>
HTML;
        }

        return implode("\n\n", $blocks);
    }

    /**
     * Колонки списка: первое поле — ссылка на карточку, дальше ещё два.
     *
     * @param array<int, array{name: string, type: string, label: string}> $fields
     *
     * @return array{heads: string, cells: string}
     */
    private function columns(array $fields): array
    {
        $extra = array_slice($fields, 1, 2);
        $heads = '';
        $cells = '';

        foreach ($extra as $field) {
            $heads .= "\n                    <th class=\"hide-sm\">" . $field['label'] . '</th>';

            $value = $field['type'] === 'bool'
                ? "<?= \$record->" . $field['name'] . " ? 'да' : 'нет' ?>"
                : "<?= View::e(Str::limit((string) \$record->raw('" . $field['name'] . "'), 80)) ?>";

            $cells .= "\n                        <td class=\"hide-sm muted small\">" . $value . '</td>';
        }

        return ['heads' => $heads, 'cells' => $cells];
    }

    /**
     * Пары «подпись — значение» для карточки.
     *
     * @param array<int, array{name: string, type: string, label: string}> $fields
     */
    private function props(array $fields): string
    {
        $lines = [];

        foreach ($fields as $field) {
            $value = $field['type'] === 'bool'
                ? "\$record->" . $field['name'] . " ? 'да' : 'нет'"
                : "(string) \$record->raw('" . $field['name'] . "')";

            $lines[] = "            '" . $field['label'] . "' => " . $value . ',';
        }

        return implode("\n", $lines);
    }

    /**
     * @param array{name: string, type: string, label: string} $field
     */
    private function testValue(array $field): string
    {
        return match ($field['type']) {
            'int', 'decimal' => '42',
            'bool'           => '1',
            'date'           => "'2026-01-01'",
            'datetime'       => "'2026-01-01 10:00:00'",
            'email'          => "'test@example.com'",
            default          => "'Проверка'",
        };
    }

    /**
     * @param array<int, array{name: string, type: string, label: string}> $fields
     */
    private function width(array $fields): int
    {
        $width = 0;

        foreach ($fields as $field) {
            $width = max($width, mb_strlen($field['name']));
        }

        return $width;
    }

    /**
     * Что осталось сделать руками: маршруты, право, меню.
     *
     * @param array<string, string>                                        $names
     * @param array<int, array{name: string, type: string, label: string}> $fields
     */
    private function hints(array $names, array $fields): void
    {
        $route  = $names['route'];
        $class  = Str::plural($names['class']) . 'Controller';
        $title  = $names['title'];

        $this->line('');
        $this->line('Осталось три шага.');
        $this->line('');
        $this->line('1. Маршруты — routes/web.php, внутрь группы auth:');
        $this->line('');
        $this->line('    $router->group([\'prefix\' => \'/' . $route . '\'], function (Router $router): void {');
        $this->line('        $router->get(\'\', [' . $class . '::class, \'index\'])->middleware(\'can:' . $route . '.view\')->name(\'' . $route . '.index\');');
        $this->line('        $router->get(\'/new\', [' . $class . '::class, \'create\'])->middleware(\'can:' . $route . '.manage\')->name(\'' . $route . '.create\');');
        $this->line('        $router->post(\'/new\', [' . $class . '::class, \'store\'])->middleware(\'can:' . $route . '.manage\');');
        $this->line('        $router->get(\'/{id:\d+}\', [' . $class . '::class, \'show\'])->middleware(\'can:' . $route . '.view\')->name(\'' . $route . '.show\');');
        $this->line('        $router->get(\'/{id:\d+}/edit\', [' . $class . '::class, \'edit\'])->middleware(\'can:' . $route . '.manage\')->name(\'' . $route . '.edit\');');
        $this->line('        $router->post(\'/{id:\d+}/edit\', [' . $class . '::class, \'update\'])->middleware(\'can:' . $route . '.manage\');');
        $this->line('        $router->post(\'/{id:\d+}/delete\', [' . $class . '::class, \'delete\'])->middleware(\'can:' . $route . '.manage\')->name(\'' . $route . '.delete\');');
        $this->line('    });');
        $this->line('');
        $this->line('    use App\Controllers\Web\\' . $class . '; — вверху файла');
        $this->line('');
        $this->line('2. Права — config/permissions.php:');
        $this->line('');
        $this->line('    Permission::register(\'' . $route . '.view\', \'Смотреть: ' . $title . '\', \'' . $title . '\');');
        $this->line('    Permission::register(\'' . $route . '.manage\', \'Заводить и править: ' . $title . '\', \'' . $title . '\');');
        $this->line('');
        $this->line('3. Пункт меню — config/menu.php:');
        $this->line('');
        $this->line('    [\'key\' => \'' . $route . '\', \'label\' => \'' . $title . '\', \'route\' => \'' . $route . '.index\', \'permission\' => \'' . $route . '.view\', \'group\' => 3],');

        if ($this->hasOption('api')) {
            $this->line('');
            $this->line('4. Маршруты API — routes/api.php, внутрь группы с \'api\':');
            $this->line('');
            $this->line('    $router->get(\'/' . $route . '\', [' . $class . '::class, \'index\'])->middleware(\'can:' . $route . '.view\')->name(\'api.' . $route . '.index\');');
            $this->line('    $router->post(\'/' . $route . '\', [' . $class . '::class, \'store\'])->middleware(\'can:' . $route . '.manage\')->name(\'api.' . $route . '.store\');');
            $this->line('    $router->get(\'/' . $route . '/{id:\d+}\', [' . $class . '::class, \'show\'])->middleware(\'can:' . $route . '.view\')->name(\'api.' . $route . '.show\');');
            $this->line('    $router->patch(\'/' . $route . '/{id:\d+}\', [' . $class . '::class, \'update\'])->middleware(\'can:' . $route . '.manage\')->name(\'api.' . $route . '.update\');');
            $this->line('    $router->delete(\'/' . $route . '/{id:\d+}\', [' . $class . '::class, \'delete\'])->middleware(\'can:' . $route . '.manage\')->name(\'api.' . $route . '.delete\');');
            $this->line('');
            $this->line('    use App\Controllers\Api\\' . $class . '; — вверху файла');
        }

        $this->line('');
        $this->line('Потом: php bin/proton migrate и php bin/proton test');
    }

    /**
     * @param array<string, string> $replacements
     */
    private function render(string $stub, array $replacements): string
    {
        $path = APP_ROOT . '/stubs/' . $stub;

        if (!is_file($path)) {
            return "<?php\n\ndeclare(strict_types=1);\n";
        }

        return strtr((string) file_get_contents($path), $replacements);
    }

    private function relative(string $path): string
    {
        return str_replace(APP_ROOT . '/', '', str_replace('\\', '/', $path));
    }
}
