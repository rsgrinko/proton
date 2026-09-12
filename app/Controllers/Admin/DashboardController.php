<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\Note;
use Rsgrinko\Proton\Cache\Cache;
use Rsgrinko\Proton\Http\Controller;
use Rsgrinko\Proton\Http\Response;
use Rsgrinko\Proton\Models\AuditEntry;
use Rsgrinko\Proton\Models\User;
use Rsgrinko\Proton\Queue\Queue;
use Rsgrinko\Proton\Support\Diagnostics;

/**
 * Обзор: несколько цифр и последние действия.
 */
final class DashboardController extends Controller
{
    public function index(): Response
    {
        // Сводку считаем не на каждое обновление страницы: цифры здесь
        // не про секундную точность
        $stats = Cache::remember('admin:dashboard', 30, static fn (): array => [
            'users'  => User::query()->count(),
            'active' => User::query()->where('active', 1)->count(),
            'notes'  => Note::query()->count(),
            'queue'  => Queue::stats(),
        ]);

        $checks = Diagnostics::run();

        return $this->view('admin/dashboard', [
            'active' => 'dashboard',
            'stats'  => $stats,
            'health' => Diagnostics::worst($checks),
            'events' => AuditEntry::query()->orderBy('id', 'desc')->limit(10)->get(),
        ], 'Панель');
    }
}
