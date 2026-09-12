<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use Rsgrinko\Proton\Http\Controller;
use Rsgrinko\Proton\Http\Request;
use Rsgrinko\Proton\Http\Response;
use Rsgrinko\Proton\Models\AuditEntry;

/**
 * Журнал действий: кто и что менял.
 */
final class AuditController extends Controller
{
    public function index(Request $request): Response
    {
        $action = $request->text('action');
        $entity = $request->text('entity');
        $search = $request->text('q');

        $query = AuditEntry::query()
            ->when($action, static fn ($query, string $value) => $query->where('action', $value))
            ->when($entity, static fn ($query, string $value) => $query->where('entity', $value))
            ->when($search, static fn ($query, string $value) => $query->whereLike('description', $value))
            ->orderBy('id', 'desc');

        return $this->view('admin/audit', [
            'active'  => 'audit',
            'page'    => $query->paginate($this->page($request), $this->perPage()),
            'filters' => ['action' => $action, 'entity' => $entity, 'q' => $search],
            'actions' => AuditEntry::LABELS,
        ], 'Журнал действий');
    }
}
