<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use Rsgrinko\Proton\Access\Viewer;
use Rsgrinko\Proton\Http\Controller;
use Rsgrinko\Proton\Http\Response;
use Rsgrinko\Proton\Models\AuditEntry;
use Rsgrinko\Proton\Support\Widgets;

/**
 * Обзор: карточки под право смотрящего и последние действия.
 *
 * Какие карточки видны — решает реестр Widgets, а не эта страница: свой
 * раздел добавляет свою карточку в config/widgets.php, ничего не трогая здесь.
 */
final class DashboardController extends Controller
{
    public function index(Viewer $viewer): Response
    {
        $canSeeAudit = $viewer->can('audit.view');

        return $this->view('admin/dashboard', [
            'active'  => 'dashboard',
            'widgets' => Widgets::for($viewer),
            // Журнал — тоже право: без audit.view человеку тут нечего смотреть
            'events'  => $canSeeAudit ? AuditEntry::query()->orderBy('id', 'desc')->limit(10)->get() : [],
            'canSeeAudit' => $canSeeAudit,
        ], 'Панель');
    }
}
