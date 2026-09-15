<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use Rsgrinko\Proton\Database\Connection;
use Rsgrinko\Proton\Http\Controller;
use Rsgrinko\Proton\Http\Request;
use Rsgrinko\Proton\Http\Response;
use Rsgrinko\Proton\Queue\Queue;
use Rsgrinko\Proton\Queue\Scheduler;
use Rsgrinko\Proton\Support\Audit;
use Rsgrinko\Proton\Support\Filter;
use Rsgrinko\Proton\Support\Filters;

/**
 * Очередь задач: что лежит, что упало и что умерло.
 *
 * Мёртвая задача — та, что исчерпала попытки. Сама она больше не повторится:
 * её разбирают руками, потому что причина обычно снаружи (чужой сервис,
 * испорченные данные), и повторять вслепую бессмысленно.
 */
final class QueueController extends Controller
{
    /** @var array<string, string> Подписи состояний */
    private const STATUSES = [
        Queue::QUEUED  => 'ждёт',
        Queue::RUNNING => 'выполняется',
        Queue::DONE    => 'выполнено',
        Queue::FAILED  => 'ошибка',
        Queue::DEAD    => 'мёртвая',
    ];

    public function index(Request $request): Response
    {
        $filters = $this->filters($request, [
            Filter::select('status', 'Состояние', self::STATUSES),
            Filter::text('queue', 'Очередь', 'queue', 'default, webhooks'),
            Filter::search('q', 'Поиск', ['job_class', 'error'], 'класс задачи или ошибка'),
            Filter::dates('when', 'Когда', 'created_at'),
        ])->sortable(['id', 'priority', 'created_at'], 'id');

        $query = $filters->apply(Connection::instance()->table('jobs'));

        return $this->view('admin/queue', [
            'active'    => 'queue',
            'page'      => $query->paginate($this->page($request), $this->perPage()),
            'filters'   => $filters,
            'stats'     => Queue::stats(),
            'statuses'  => self::STATUSES,
            'schedule'  => Scheduler::tasks(),
        ], 'Очередь');
    }

    /**
     * Вернуть задачу в очередь: попытки обнуляются.
     */
    public function retry(Request $request): Response
    {
        $ids = $this->ids($request);

        $done = 0;

        foreach ($ids as $id) {
            $done += Queue::retry($id) ? 1 : 0;
        }

        Audit::action('queue', 0, 'возвращено в очередь задач: ' . $done);

        $this->flash($done > 0 ? 'Вернул в очередь: ' . $done : 'Нечего возвращать', $done > 0 ? 'ok' : 'error');

        return $this->redirect('admin.queue');
    }

    /**
     * Убрать задачи совсем.
     */
    public function delete(Request $request): Response
    {
        $ids = $this->ids($request);

        if ($ids === []) {
            $this->flash('Не отмечено ни одной задачи', 'error');

            return $this->redirect('admin.queue');
        }

        $done = Connection::instance()->table('jobs')->whereIn('id', $ids)->delete();

        Audit::action('queue', 0, 'удалено задач: ' . $done);

        $this->flash('Удалено: ' . $done);

        return $this->redirect('admin.queue');
    }

    /**
     * Выполнить задачу расписания прямо сейчас. Параллельный запуск закрыт
     * блокировкой: два одновременных прогона рассылки — это два письма каждому.
     */
    public function run(Request $request): Response
    {
        $name = trim((string) $request->input('task', ''));

        $result = Scheduler::runNow($name);

        if ($result === null) {
            $this->flash('Такой задачи в расписании нет', 'error');

            return $this->redirect('admin.queue');
        }

        if ($result === false) {
            $this->flash('Задача уже выполняется — подождите', 'error');

            return $this->redirect('admin.queue');
        }

        Audit::action('schedule', 0, 'задача расписания запущена вручную: ' . $name);

        $this->flash('Задача «' . $name . '» выполнена');

        return $this->redirect('admin.queue');
    }

    /**
     * Отмеченные задачи из формы.
     *
     * @return array<int, int>
     */
    private function ids(Request $request): array
    {
        return array_values(array_filter(array_map('intval', (array) $request->input('ids', []))));
    }
}
