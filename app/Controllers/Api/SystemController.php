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
     * Наружу отдаём только то, что не жалко: живость базы и размер очереди.
     */
    public function health(): Response
    {
        $database = 'ok';

        try {
            Connection::instance()->value('SELECT 1');
        } catch (Throwable $e) {
            $database = 'fail';
        }

        $queue = [];

        try {
            $queue = Queue::stats();
        } catch (Throwable) {
            $database = 'fail';
        }

        $healthy = $database === 'ok';

        return Response::json([
            'status'   => $healthy ? 'ok' : 'fail',
            'app'      => (string) Config::get('app.name', 'Proton'),
            'database' => $database,
            'queue'    => $queue,
            'time'     => date('c'),
        ], $healthy ? 200 : 503);
    }
}
