<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Queue;

/**
 * Задача очереди.
 *
 *     final class SendReport extends Job
 *     {
 *         public function handle(array $payload): void
 *         {
 *             // здесь долгая работа: письма, выгрузки, обращения к чужим API
 *         }
 *     }
 *
 *     Queue::push(SendReport::class, ['user_id' => 7], 60);
 *
 * Задача обязана переживать повтор: воркер может выполнить её второй раз, если
 * процесс упал между работой и отметкой об успехе.
 *
 * Цепочка (`Queue::chain()`) ставит следующий шаг только после успеха
 * предыдущего — упавшая насовсем задача останавливает её сама. Данные,
 * которые появились только во время работы (сгенерированное имя файла и
 * подобное), следующий шаг получает через `carryToNext()`, а не через
 * payload — заранее их не бывает:
 *
 *     Queue::chain([
 *         [ExportJob::class, ['kind' => 'users', 'user_id' => 5]],
 *         [NotifyExportReadyJob::class],
 *     ]);
 */
abstract class Job
{
    /** @var array<string, mixed> Данные для следующего шага цепочки (Queue::chain()) */
    private array $carry = [];

    /**
     * Сколько раз пробовать, прежде чем признать задачу неудавшейся.
     */
    public function attempts(): int
    {
        return 3;
    }

    /**
     * Через сколько секунд повторять после ошибки. Пауза растёт с каждой
     * попыткой: чужой сервис, который лежит, не нужно долбить каждую секунду.
     */
    public function backoff(int $attempt): int
    {
        $pause = min(3600, 30 * (2 ** max(0, $attempt - 1)));

        // Разброс до четверти паузы: сотня задач, упавших из-за одного и того же
        // лежащего сервиса, не должна вернуться к нему одновременно
        return $pause + random_int(0, max(1, (int) ($pause / 4)));
    }

    /**
     * Насколько задача важнее прочих: воркер берёт сначала то, у чего число
     * больше. Письмо со сбросом пароля не должно ждать тысячи рассылок.
     */
    public function priority(): int
    {
        return 0;
    }

    /**
     * В какую очередь класть по умолчанию: разные очереди можно разбирать
     * разными воркерами.
     */
    public function queue(): string
    {
        return Queue::DEFAULT_QUEUE;
    }

    /**
     * Сама работа.
     *
     * @param array<string, mixed> $payload
     */
    abstract public function handle(array $payload): void;

    /**
     * Передать данные следующему шагу цепочки (Queue::chain()) — тому, что
     * появилось только сейчас и заранее в очереди лежать не могло: сгенерированное
     * имя файла, id только что созданной записи. Не имеет смысла вне цепочки —
     * если следующего шага нет, значение просто некому будет прочитать.
     *
     * @param array<string, mixed> $data
     */
    protected function carryToNext(array $data): void
    {
        $this->carry = $data;
    }

    /**
     * Что задача передала следующему шагу — читает воркер после handle().
     *
     * @return array<string, mixed>
     */
    public function forwarded(): array
    {
        return $this->carry;
    }

    /**
     * Задача исчерпала попытки. Здесь уместно сообщить о поломке.
     *
     * @param array<string, mixed> $payload
     */
    public function failed(array $payload, string $error): void
    {
        // По умолчанию ничего: запись об ошибке уже есть в очереди и в логе
    }
}
