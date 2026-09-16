<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use Rsgrinko\Proton\Access\Permission;
use Rsgrinko\Proton\Access\Viewer;
use Rsgrinko\Proton\Database\Connection;
use Rsgrinko\Proton\Http\Response;
use Rsgrinko\Proton\Models\ApiToken;
use Rsgrinko\Proton\Models\User;
use Rsgrinko\Proton\Queue\Queue;
use Rsgrinko\Proton\Support\Config;
use Rsgrinko\Proton\Support\Metrics;
use Throwable;

/**
 * Служебные адреса API: кто я и жив ли сервис.
 */
final class SystemController extends ApiController
{
    /**
     * Кто пришёл по ключу. Первое, что дёргают при отладке интеграции.
     */
    public function me(Viewer $viewer, User $user, ApiToken $token): Response
    {
        return $this->data([
            'user' => [
                'id'          => $user->id(),
                'login'       => (string) $user->login,
                'name'        => (string) $user->name,
                'email'       => (string) $user->email,
                'permissions' => $viewer->permissions(),
            ],
            'token' => [
                'id'   => $token->id(),
                'name' => (string) $token->name,
                'mask' => $token->mask(),
            ],
        ]);
    }

    /**
     * Показатели работы для мониторинга. В отличие от /health, здесь цифры
     * о внутренностях, поэтому нужен ключ и право system.view.
     */
    public function metrics(Viewer $viewer): Response
    {
        if (!$viewer->can(Permission::SYSTEM_VIEW)) {
            return Response::error('Нет доступа к показателям', 403);
        }

        return $this->data(Metrics::snapshot());
    }

    /**
     * Здоровье сервиса — его дёргает мониторинг, поэтому ключ здесь не нужен.
     * Наружу отдаём только то, что не жалко: по каждой зависимости — свой
     * статус отдельным полем, а не одно общее «зелёный/красный». Мониторинг,
     * которому важно только «жив/не жив», смотрит на верхний `status`; кому
     * нужна причина — читает `checks`.
     */
    public function health(): Response
    {
        $checks = [
            'database' => $this->healthDatabase(),
            'disk'     => $this->healthDisk(),
            'queue'    => $this->healthQueue(),
        ];

        $status = 'ok';

        foreach ($checks as $check) {
            if ($check['status'] === 'fail') {
                $status = 'fail';

                break;
            }

            if ($check['status'] === 'warn') {
                $status = 'warn';
            }
        }

        return Response::json([
            'status' => $status,
            'app'    => (string) Config::get('app.name', 'Proton'),
            'checks' => $checks,
            'time'   => date('c'),
        ], $status === 'fail' ? 503 : 200);
    }

    /**
     * @return array{status: string, ms?: float}
     */
    private function healthDatabase(): array
    {
        $startedAt = microtime(true);

        try {
            Connection::instance()->value('SELECT 1');

            return ['status' => 'ok', 'ms' => round((microtime(true) - $startedAt) * 1000, 1)];
        } catch (Throwable) {
            return ['status' => 'fail'];
        }
    }

    /**
     * Тот же порог, что у присмотра (MONITOR_FREE_BYTES): здоровье и мониторинг
     * не должны спорить о том, что считать «мало места».
     *
     * @return array{status: string, free_mb: int}
     */
    private function healthDisk(): array
    {
        $free  = (int) (Metrics::storage()['free_bytes'] ?? 0);
        $limit = (int) Config::get('monitor.free_bytes', 500 * 1024 * 1024);

        return [
            'status'  => $free >= $limit ? 'ok' : 'warn',
            'free_mb' => (int) round($free / 1024 / 1024),
        ];
    }

    /**
     * @return array{status: string, queued?: int, failed?: int}
     */
    private function healthQueue(): array
    {
        try {
            $stats  = Queue::stats();
            $failed = (int) ($stats[Queue::FAILED] ?? 0) + (int) ($stats[Queue::DEAD] ?? 0);

            return [
                'status' => $failed === 0 ? 'ok' : 'warn',
                'queued' => (int) ($stats[Queue::QUEUED] ?? 0),
                'failed' => $failed,
            ];
        } catch (Throwable) {
            return ['status' => 'fail'];
        }
    }
}
