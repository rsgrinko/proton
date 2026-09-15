<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Database\Model;

use Rsgrinko\Proton\Support\ProtonException;

/**
 * Фабрики моделей: заготовка записи с правдоподобными полями.
 *
 *     Factory::define(Note::class, static fn (int $i): array => [
 *         'title' => 'Заметка ' . $i,
 *         'body'  => Factory::sentence(),
 *     ]);
 *
 *     $note  = Factory::of(Note::class)->create();               // одна запись
 *     $notes = Factory::of(Note::class)->times(10)->create();    // десять
 *     $draft = Factory::of(Note::class)->make(['title' => 'Своё']); // без записи в базу
 *
 * Нужна и тестам, и сидерам: описание лежит в `database/factories.php`, поэтому
 * одна и та же заготовка наполняет и тестовую базу, и базу разработчика.
 *
 * Случайные значения нарочно простые и без внешних библиотек: фабрике нужно,
 * чтобы поля были разными и влезали в ограничения, а не чтобы выглядели
 * как настоящие имена.
 */
final class Factory
{
    /** @var array<class-string<Model>, callable(int): array<string, mixed>> */
    private static array $definitions = [];

    /** Счётчик вызовов на класс — им отличаются логины и адреса */
    private static array $counters = [];

    /** @var class-string<Model> */
    private string $model;

    private int $times = 1;

    /** @var array<string, mixed> Что перебить поверх заготовки */
    private array $overrides = [];

    /** @var array<int, callable(Model): void> Что сделать с каждой записью после создания */
    private array $after = [];

    /**
     * @param class-string<Model> $model
     */
    private function __construct(string $model)
    {
        $this->model = $model;
    }

    /**
     * Описание фабрики. Зовётся из database/factories.php.
     *
     * @param class-string<Model>                 $model
     * @param callable(int): array<string, mixed> $definition
     */
    public static function define(string $model, callable $definition): void
    {
        self::$definitions[$model] = $definition;
    }

    /**
     * Фабрика модели.
     *
     * @param class-string<Model> $model
     */
    public static function of(string $model): self
    {
        self::boot();

        if (!isset(self::$definitions[$model])) {
            throw new ProtonException(
                'Для ' . $model . ' нет фабрики. Опишите её в database/factories.php через Factory::define()'
            );
        }

        return new self($model);
    }

    /**
     * Есть ли описание — сидеру полезно проверить, не падая.
     *
     * @param class-string<Model> $model
     */
    public static function has(string $model): bool
    {
        self::boot();

        return isset(self::$definitions[$model]);
    }

    /**
     * Забыть описания и счётчики: нужно тестам, которые заводят свои фабрики.
     */
    public static function reset(): void
    {
        self::$definitions = [];
        self::$counters    = [];
    }

    /**
     * Сколько записей делать.
     */
    public function times(int $times): self
    {
        $this->times = max(1, $times);

        return $this;
    }

    /**
     * Поля поверх заготовки — то, что в тесте важно, задаётся явно.
     *
     * @param array<string, mixed> $attributes
     */
    public function state(array $attributes): self
    {
        $this->overrides = array_merge($this->overrides, $attributes);

        return $this;
    }

    /**
     * Что сделать с каждой созданной записью: связи, файлы, отметки.
     *
     * @param callable(Model): void $callback
     */
    public function afterCreating(callable $callback): self
    {
        $this->after[] = $callback;

        return $this;
    }

    /**
     * Модель без записи в базу.
     *
     * @param array<string, mixed> $attributes
     *
     * @return Model|array<int, Model>
     */
    public function make(array $attributes = []): Model|array
    {
        $made = [];

        for ($number = 0; $number < $this->times; $number++) {
            $model = new $this->model();

            $model->forceFill($this->attributes($attributes));

            $made[] = $model;
        }

        return $this->times === 1 ? $made[0] : $made;
    }

    /**
     * Запись в базе. Одна модель или список — по times().
     *
     * @param array<string, mixed> $attributes
     *
     * @return Model|array<int, Model>
     */
    public function create(array $attributes = []): Model|array
    {
        $created = [];

        for ($number = 0; $number < $this->times; $number++) {
            /** @var Model $model */
            $model = new $this->model();

            $model->forceFill($this->attributes($attributes));
            $model->save();

            foreach ($this->after as $callback) {
                $callback($model);
            }

            $created[] = $model;
        }

        return $this->times === 1 ? $created[0] : $created;
    }

    /**
     * Строка из нескольких слов — для названий и текстов.
     */
    public static function sentence(int $words = 6): string
    {
        $dictionary = [
            'заметка', 'отчёт', 'проверка', 'черновик', 'список', 'задача', 'письмо',
            'счёт', 'договор', 'встреча', 'заявка', 'правка', 'вопрос', 'ответ',
        ];

        $picked = [];

        for ($number = 0; $number < max(1, $words); $number++) {
            $picked[] = $dictionary[array_rand($dictionary)];
        }

        $text = implode(' ', $picked);

        return mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1);
    }

    /**
     * Уникальное слово: логины и адреса должны быть разными в пределах прогона.
     */
    public static function unique(string $prefix = ''): string
    {
        return ($prefix !== '' ? $prefix . '_' : '') . bin2hex(random_bytes(4));
    }

    /**
     * Дата в прошлом — для created_at и отметок активности.
     */
    public static function pastDate(int $maxDaysAgo = 30): string
    {
        return date('Y-m-d H:i:s', time() - random_int(0, max(1, $maxDaysAgo)) * 86400);
    }

    /**
     * Заготовка плюс то, что перебили.
     *
     * @param array<string, mixed> $attributes
     *
     * @return array<string, mixed>
     */
    private function attributes(array $attributes): array
    {
        $counter = self::$counters[$this->model] = (self::$counters[$this->model] ?? 0) + 1;

        $definition = (self::$definitions[$this->model])($counter);

        return array_merge((array) $definition, $this->overrides, $attributes);
    }

    /**
     * Описания приложения — читаются один раз.
     */
    private static function boot(): void
    {
        if (self::$definitions !== []) {
            return;
        }

        $file = APP_ROOT . '/database/factories.php';

        if (is_file($file)) {
            require $file;
        }
    }
}
