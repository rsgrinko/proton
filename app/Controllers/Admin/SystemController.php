<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use Rsgrinko\Proton\Cache\Cache;
use Rsgrinko\Proton\Database\Migrator;
use Rsgrinko\Proton\Events\Events;
use Rsgrinko\Proton\Http\Controller;
use Rsgrinko\Proton\Http\Request;
use Rsgrinko\Proton\Http\Response;
use Rsgrinko\Proton\Models\Setting;
use Rsgrinko\Proton\Queue\Queue;
use Rsgrinko\Proton\Queue\Scheduler;
use Rsgrinko\Proton\Queue\Worker;
use Rsgrinko\Proton\Support\Audit;
use Rsgrinko\Proton\Support\Diagnostics;
use Rsgrinko\Proton\Support\Metrics;
use Throwable;

/**
 * Состояние: самопроверка, очередь, расписание и кнопки обслуживания.
 *
 * Проверки только читают, поэтому страница открывается быстро и ничего
 * не ломает. Всё, что меняет состояние, — отдельными кнопками и под правом
 * system.manage.
 */
final class SystemController extends Controller
{
    public function index(): Response
    {
        $checks = Diagnostics::run();

        return $this->view('admin/system', [
            'active'   => 'system',
            'checks'   => $checks,
            'health'   => Diagnostics::worst($checks),
            'metrics'  => Metrics::requests(24),
            'storage'  => Metrics::storage(),
            'queue'    => Queue::stats(),
            'schedule' => Scheduler::tasks(),
            'events'   => Events::registered(),
            'settings' => Setting::all(),
            'pending'  => (new Migrator())->pending(),
            'php'      => PHP_VERSION,
        ], 'Состояние');
    }

    /**
     * Кнопки обслуживания. Право проверяется прослойкой у группы,
     * само действие — по карте ниже.
     */
    public function action(Request $request, string $action): Response
    {
        switch ($action) {
            case 'worker-restart':
                Worker::requestRestart();

                Audit::action('system', 0, 'запрошен перезапуск воркера');

                $this->flash('Воркер выйдет после текущего круга, служба поднимет его заново');

                break;

            case 'cache-clear':
                Cache::flush();

                Audit::action('system', 0, 'очищен кэш');

                $this->flash('Кэш очищен');

                break;

            case 'queue-purge':
                $removed = Queue::purge(0);
                $stuck   = Queue::releaseStuck();

                Audit::action('system', 0, 'подчищена очередь: удалено ' . $removed . ', возвращено ' . $stuck);

                $this->flash('Удалено выполненных: ' . $removed . ', возвращено зависших: ' . $stuck);

                break;

            case 'queue-retry':
                $count = 0;

                foreach (Queue::stats() as $status => $total) {
                    if ($status === Queue::FAILED) {
                        $count = $total;
                    }
                }

                foreach (\Rsgrinko\Proton\Database\Connection::instance()->select(
                    'SELECT id FROM jobs WHERE status = :status',
                    ['status' => Queue::FAILED]
                ) as $row) {
                    Queue::retry((int) $row['id']);
                }

                Audit::action('system', 0, 'возвращены в очередь неудавшиеся задачи: ' . $count);

                $this->flash('Возвращено в очередь: ' . $count);

                break;

            case 'schedule-run':
                $done = Scheduler::run();

                Audit::action('system', 0, 'вручную выполнено расписание: ' . implode(', ', $done));

                $this->flash($done === [] ? 'Задач, которым пора, нет' : 'Выполнено: ' . implode(', ', $done));

                break;

            case 'migrate':
                try {
                    $applied = (new Migrator())->run();
                } catch (Throwable $e) {
                    // Накат мог упасть на середине — сообщаем как есть, самопроверка
                    // на этой же странице покажет, что осталось не применённым
                    Audit::action('system', 0, 'накат миграций из панели не удался: ' . $e->getMessage());

                    $this->flash('Не применились: ' . $e->getMessage(), 'error');

                    break;
                }

                Audit::action('system', 0, $applied === [] ? 'накат миграций из панели: новых нет' : 'применены миграции: ' . implode(', ', $applied));

                $this->flash($applied === [] ? 'Новых миграций нет' : 'Применены: ' . implode(', ', $applied));

                break;

            default:
                $this->flash('Неизвестное действие', 'error');
        }

        return $this->redirect('admin.system');
    }
}
