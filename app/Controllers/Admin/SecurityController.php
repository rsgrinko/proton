<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use Rsgrinko\Proton\Http\Controller;
use Rsgrinko\Proton\Http\Request;
use Rsgrinko\Proton\Http\Response;
use Rsgrinko\Proton\Models\BlockedIp;
use Rsgrinko\Proton\Models\SecurityEvent;
use Rsgrinko\Proton\Models\User;
use Rsgrinko\Proton\Support\Audit;
use Rsgrinko\Proton\Support\Filter;
use Rsgrinko\Proton\Support\Filters;
use Rsgrinko\Proton\View\View;

/**
 * Подозрительная активность: кто не смог войти, откуда ломились и какие адреса
 * закрыты.
 *
 * Здесь же адрес закрывают и открывают — руками. Сама по себе блокировка
 * появляется только от перебора паролей (настройки SECURITY_AUTOBLOCK_*).
 */
final class SecurityController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $this->listFilters($request);

        return $this->view('admin/security', [
            'active'  => 'security',
            'page'    => $filters->apply(SecurityEvent::query())->paginate($this->page($request), $this->perPage()),
            'filters' => $filters,
            'blocks'  => BlockedIp::query()->orderBy('id', 'desc')->limit(100)->get(),
            'authors' => $this->authors(),
            'top'     => $this->noisiest(),
        ], 'Безопасность');
    }

    public function export(Request $request): Response
    {
        $filters = $this->listFilters($request);

        return $this->exportCsv($filters->apply(SecurityEvent::query()), [
            'created_at' => 'Когда',
            'kind'       => ['Событие', static fn (SecurityEvent $event): string => SecurityEvent::label((string) $event->raw('kind'))],
            'ip'         => 'Адрес',
            'login'      => 'Логин',
            'path'       => 'Страница',
            'agent'      => 'Клиент',
        ], 'security', 'security', 'admin.security');
    }

    /**
     * Закрыть адрес. Срок в минутах; 0 — навсегда.
     */
    public function block(Request $request): Response
    {
        $data = $this->validate($request, [
            'ip'      => 'required|max:64',
            'reason'  => 'nullable|max:191',
            'minutes' => 'nullable|integer',
        ], ['ip' => 'Адрес', 'reason' => 'Причина', 'minutes' => 'Срок в минутах']);

        $ip = trim((string) $data['ip']);

        // Свой адрес закрывать не даём: администратор запрёт сам себя
        if ($ip === $request->ip()) {
            $this->flash('Это ваш собственный адрес — закрывать его нельзя', 'error');

            return $this->redirect('admin.security');
        }

        $minutes = (int) ($data['minutes'] ?? 60);

        BlockedIp::block($ip, (string) ($data['reason'] ?? ''), $minutes, (int) $request->attribute('user')?->id());

        Audit::action('security', 0, 'закрыт адрес ' . $ip . ($minutes > 0 ? ' на ' . $minutes . ' мин' : ' навсегда'));

        $this->flash('Адрес ' . $ip . ' закрыт');

        return $this->redirect('admin.security');
    }

    /**
     * Открыть адрес обратно.
     */
    public function unblock(Request $request): Response
    {
        $ip = trim((string) $request->input('ip', ''));

        if ($ip === '' || !BlockedIp::unblock($ip)) {
            $this->flash('Такой блокировки нет', 'error');

            return $this->redirect('admin.security');
        }

        Audit::action('security', 0, 'открыт адрес ' . $ip);

        $this->flash('Адрес ' . $ip . ' открыт');

        return $this->redirect('admin.security');
    }

    private function listFilters(Request $request): Filters
    {
        return $this->filters($request, [
            Filter::select('kind', 'Событие', SecurityEvent::LABELS),
            Filter::text('ip', 'Адрес', 'ip', '127.0.0.1'),
            Filter::search('q', 'Поиск', ['login', 'path'], 'логин или страница'),
            Filter::dates('when', 'Когда', 'created_at'),
        ])->sortable(['id', 'created_at'], 'id');
    }

    /**
     * Логины, которые чаще всего подбирают — по ним видно, ломятся ли
     * в конкретную учётную запись.
     *
     * @return array<string, int>
     */
    private function authors(): array
    {
        $rows = SecurityEvent::query()
            ->select('login', 'COUNT(*) AS total')
            ->where('kind', SecurityEvent::LOGIN_FAILED)
            ->where('login', '!=', '')
            ->groupBy('login')
            ->orderBy('total', 'desc')
            ->limit(5)
            ->rows();

        $out = [];

        foreach ($rows as $row) {
            $out[(string) $row['login']] = (int) $row['total'];
        }

        return $out;
    }

    /**
     * Самые шумные адреса за сутки.
     *
     * @return array<string, int>
     */
    private function noisiest(): array
    {
        $rows = SecurityEvent::query()
            ->select('ip', 'COUNT(*) AS total')
            ->where('created_at', '>=', date('Y-m-d H:i:s', time() - 86400))
            ->where('ip', '!=', '')
            ->groupBy('ip')
            ->orderBy('total', 'desc')
            ->limit(5)
            ->rows();

        $out = [];

        foreach ($rows as $row) {
            $out[(string) $row['ip']] = (int) $row['total'];
        }

        return $out;
    }
}
