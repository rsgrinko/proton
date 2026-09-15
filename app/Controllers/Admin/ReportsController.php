<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\Note;
use Rsgrinko\Proton\Http\Controller;
use Rsgrinko\Proton\Http\Request;
use Rsgrinko\Proton\Http\Response;
use Rsgrinko\Proton\Models\AuditEntry;
use Rsgrinko\Proton\Models\User;
use Rsgrinko\Proton\Models\WebhookDelivery;
use Rsgrinko\Proton\Support\Csv;
use Rsgrinko\Proton\Support\Export;
use Rsgrinko\Proton\Support\Reports;

/**
 * Отчёты: что происходило за период. Считает база, страница только рисует.
 *
 * Свой отчёт добавляется одним элементом в reports(): запрос, колонка времени
 * и подпись — остальное общее, включая выгрузку.
 */
final class ReportsController extends Controller
{
    /** Периоды, за которые показываем отчёты */
    private const RANGES = [
        '7'  => 'неделя',
        '30' => 'месяц',
        '90' => 'три месяца',
        '365' => 'год',
    ];

    public function index(Request $request): Response
    {
        [$from, $to, $days, $step] = $this->period($request);

        $reports = [];

        foreach ($this->sources() as $key => $source) {
            $series = Reports::series($source['query'](), $source['column'], $from, $to, $step);

            $reports[$key] = [
                'label'  => $source['label'],
                'series' => $series,
                'total'  => Reports::total($series),
                'max'    => max([1, ...array_values($series)]),
            ];
        }

        return $this->view('admin/reports', [
            'active'  => 'reports',
            'reports' => $reports,
            'actions' => Reports::breakdown(AuditEntry::query(), 'action'),
            'hooks'   => Reports::breakdown(WebhookDelivery::query(), 'status'),
            'ranges'  => self::RANGES,
            'days'    => $days,
            'step'    => $step,
            'from'    => $from,
            'to'      => $to,
        ], 'Отчёты');
    }

    /**
     * Выгрузка одного отчёта рядом: период, значение.
     */
    public function export(Request $request): Response
    {
        [$from, $to, , $step] = $this->period($request);

        $sources = $this->sources();
        $key     = (string) $request->query('report', '');

        if (!isset($sources[$key])) {
            $this->flash('Неизвестный отчёт', 'error');

            return $this->redirect('admin.reports');
        }

        $series = Reports::series($sources[$key]['query'](), $sources[$key]['column'], $from, $to, $step);
        $rows   = [];

        foreach ($series as $period => $count) {
            $rows[] = [$period, $count];
        }

        return Response::download(
            Csv::write($rows, ['Период', $sources[$key]['label']]),
            Export::fileName('report-' . $key),
            'text/csv; charset=utf-8'
        );
    }

    /**
     * Отчёты приложения: запрос и колонка времени.
     *
     * @return array<string, array{label: string, column: string, query: callable}>
     */
    private function sources(): array
    {
        return [
            'users' => [
                'label'  => 'Регистрации',
                'column' => 'created_at',
                'query'  => static fn () => User::query(),
            ],
            'logins' => [
                'label'  => 'Входы',
                'column' => 'created_at',
                'query'  => static fn () => AuditEntry::query()->where('action', AuditEntry::LOGIN),
            ],
            'notes' => [
                'label'  => 'Заметки',
                'column' => 'created_at',
                'query'  => static fn () => Note::query(),
            ],
            'webhooks' => [
                'label'  => 'Посылки вебхуков',
                'column' => 'created_at',
                'query'  => static fn () => WebhookDelivery::query(),
            ],
        ];
    }

    /**
     * Период отчёта: сколько дней назад смотрим и с каким шагом.
     *
     * @return array{0: string, 1: string, 2: int, 3: string}
     */
    private function period(Request $request): array
    {
        $days = (int) $request->query('days', 30);

        if (!isset(self::RANGES[(string) $days])) {
            $days = 30;
        }

        // На длинном периоде дневной ряд нечитаем — переходим на недели и месяцы
        $step = match (true) {
            $days > 180 => Reports::MONTH,
            $days > 60  => Reports::WEEK,
            default     => Reports::DAY,
        };

        return [date('Y-m-d', time() - ($days - 1) * 86400), date('Y-m-d'), $days, $step];
    }
}
