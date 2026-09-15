<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use Rsgrinko\Proton\Http\Controller;
use Rsgrinko\Proton\Http\Request;
use Rsgrinko\Proton\Http\Response;
use Rsgrinko\Proton\Models\AuditEntry;
use Rsgrinko\Proton\Support\Filter;
use Rsgrinko\Proton\Support\Filters;

/**
 * Журнал действий: кто и что менял.
 */
final class AuditController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $this->listFilters($request);

        return $this->view('admin/audit', [
            'active'  => 'audit',
            'page'    => $filters->apply(AuditEntry::query())->paginate($this->page($request), $this->perPage()),
            'filters' => $filters,
        ], 'Журнал действий');
    }

    public function export(Request $request): Response
    {
        $filters = $this->listFilters($request);

        return $this->exportCsv($filters->apply(AuditEntry::query()), [
            'created_at'  => 'Когда',
            'user_login'  => 'Кто',
            'action'      => ['Действие', static fn (AuditEntry $entry): string => AuditEntry::label((string) $entry->raw('action'))],
            'entity'      => 'Раздел',
            'entity_id'   => 'Запись',
            'description' => 'Описание',
            'ip'          => 'Адрес',
        ], 'audit', 'audit', 'admin.audit');
    }

    private function listFilters(Request $request): Filters
    {
        return $this->filters($request, [
            Filter::select('action', 'Действие', AuditEntry::LABELS),
            Filter::text('entity', 'Раздел', 'entity', 'user, role, note'),
            Filter::search('q', 'Поиск', ['description', 'user_login'], 'описание или логин'),
            Filter::dates('when', 'Когда', 'created_at'),
        ])->sortable(['id', 'created_at'], 'id');
    }
}
