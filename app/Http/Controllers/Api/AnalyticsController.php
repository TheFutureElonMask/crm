<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AnalyticsController extends Controller
{
    public function index(Request $request)
    {
        $period = $request->input('period', 'week');
        $cacheKey = "analytics_{$period}";

        return Cache::remember($cacheKey, 120, fn() => $this->getAnalyticsData($period));
    }

    /**
     * Экспорт дашборда в Excel или PDF.
     * GET /admin/analytics/export?format=excel|pdf&period=week|month
     */
    public function export(Request $request): StreamedResponse|\Illuminate\Http\Response|\Illuminate\Http\JsonResponse
    {
        $request->validate([
            'format' => 'required|string|in:excel,pdf',
            'period' => 'nullable|string|in:week,month',
        ]);
        $format = $request->query('format');
        $period = $request->query('period', 'week');
        $data = $this->getAnalyticsData($period);

        if ($format === 'excel') {
            return $this->exportDashboardToExcel($data, $period);
        }
        set_time_limit(120);
        try {
            return $this->exportDashboardToPdf($data, $period);
        } catch (\Throwable $e) {
            \Log::error('Dashboard PDF export failed', ['exception' => $e->getMessage()]);
            return response()->json(['message' => 'Ошибка генерации PDF: ' . $e->getMessage()], 500);
        }
    }

    protected function getAnalyticsData(string $period): array
    {
        $daysCount = $period === 'month' ? 30 : 7;
        $startDate = now()->subDays($daysCount - 1)->startOfDay();

        $mainStats = Lead::selectRaw("
            COUNT(*) as total,
            SUM(CASE WHEN DATE(created_at) = CURRENT_DATE THEN 1 ELSE 0 END) as today_new,
            SUM(CASE WHEN status IN ('processing', 'offer') THEN price ELSE 0 END) as active_sum,
            SUM(CASE WHEN status IN ('processing', 'offer') THEN 1 ELSE 0 END) as active_count,
            SUM(CASE WHEN status = 'won' THEN 1 ELSE 0 END) as won_count
        ")->first();

        $stats = [
            'total_leads'      => (int)$mainStats->total,
            'new_leads_today'  => (int)$mainStats->today_new,
            'active_deals_sum' => (float)$mainStats->active_sum,
            'in_progress_count'=> (int)$mainStats->active_count,
            'conversion_rate'  => $mainStats->total > 0
                ? round(($mainStats->won_count / $mainStats->total) * 100, 1)
                : 0,
            'total_won'        => (int)$mainStats->won_count,
        ];

        $dbStats = Lead::where('created_at', '>=', $startDate)
            ->selectRaw("
                DATE(created_at) as date,
                COUNT(*) as opened,
                SUM(CASE WHEN status = 'won' THEN 1 ELSE 0 END) as won,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as lost,
                SUM(CASE WHEN status = 'won' THEN price ELSE 0 END) as revenue
            ")
            ->groupBy('date')
            ->get()
            ->keyBy('date');

        $dailyData = collect(range(0, $daysCount - 1))->map(function ($days) use ($startDate, $dbStats) {
            $date = $startDate->copy()->addDays($days)->toDateString();
            $dayData = $dbStats->get($date);
            return [
                'date'    => $date,
                'opened'  => $dayData->opened ?? 0,
                'won'     => $dayData->won ?? 0,
                'lost'    => $dayData->lost ?? 0,
                'revenue' => (float)($dayData->revenue ?? 0),
            ];
        })->values();

        $managers = User::where('role', 'manager')
            ->withCount([
                'leads as new_leads' => fn($q) => $q->where('status', 'new'),
                'leads as processing_leads' => fn($q) => $q->whereIn('status', ['processing', 'offer']),
                'leads as won_leads' => fn($q) => $q->where('status', 'won'),
                'leads as lost_leads' => fn($q) => $q->where('status', 'rejected'),
            ])
            ->withSum(['leads as total_money' => fn($q) => $q->where('status', 'won')], 'price')
            ->orderByDesc('won_leads')
            ->get()
            ->map(fn($u) => [
                'name'             => $u->name,
                'new_leads'        => $u->new_leads,
                'processing_leads' => $u->processing_leads,
                'won_leads'        => $u->won_leads,
                'lost_leads'       => $u->lost_leads,
                'total_money'      => (float)($u->total_money ?? 0),
            ]);

        return [
            'stats'       => $stats,
            'daily_stats' => $dailyData,
            'managers'    => $managers,
        ];
    }

    protected function exportDashboardToExcel(array $data, string $period): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();

        $periodLabel = $period === 'month' ? 'Месяц' : 'Неделя';
        $stats = $data['stats'];
        $dailyStats = $data['daily_stats'];
        $managers = $data['managers'];

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Сводка');
        $sheet->setCellValue('A1', 'Статистика дашборда');
        $sheet->setCellValue('A2', "Период: {$periodLabel}. Сформировано: " . now()->format('d.m.Y H:i'));
        $sheet->setCellValue('A4', 'Показатель');
        $sheet->setCellValue('B4', 'Значение');
        $sheet->fromArray([
            ['Всего в базе', $stats['total_leads']],
            ['Сумма сделок (₸)', $stats['active_deals_sum']],
            ['В процессе', $stats['in_progress_count']],
            ['Конверсия %', $stats['conversion_rate']],
        ], null, 'A5');
        $sheet->getStyle('A1:B1')->getFont()->setBold(true);
        $sheet->getStyle('A4:B4')->getFont()->setBold(true);
        $sheet->getStyle('A4:B4')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E2E8F0');

        $row = 12;
        $sheet->setCellValue('A' . $row, 'Эффективность по дням');
        $sheet->getStyle('A' . $row)->getFont()->setBold(true);
        $row++;
        $sheet->fromArray([['Дата', 'Создано', 'Закрыто успешно', 'Отказов', 'Выручка (₸)']], null, 'A' . $row);
        $sheet->getStyle('A' . $row . ':E' . $row)->getFont()->setBold(true);
        $sheet->getStyle('A' . $row . ':E' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E2E8F0');
        $row++;
        foreach ($dailyStats as $d) {
            $sheet->fromArray([
                [$d['date'], $d['opened'], $d['won'], $d['lost'], $d['revenue']],
            ], null, 'A' . $row);
            $row++;
        }

        $row += 2;
        $sheet->setCellValue('A' . $row, 'Лидеры периода');
        $sheet->getStyle('A' . $row)->getFont()->setBold(true);
        $row++;
        $sheet->fromArray([['Менеджер', 'Новых', 'В работе', 'Успешно', 'Отказов', 'Выручка (₸)']], null, 'A' . $row);
        $sheet->getStyle('A' . $row . ':F' . $row)->getFont()->setBold(true);
        $sheet->getStyle('A' . $row . ':F' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E2E8F0');
        $row++;
        foreach ($managers as $m) {
            $sheet->fromArray([
                [$m['name'], $m['new_leads'], $m['processing_leads'], $m['won_leads'], $m['lost_leads'], $m['total_money']],
            ], null, 'A' . $row);
            $row++;
        }

        foreach (range('A', 'F') as $c) {
            $sheet->getColumnDimension($c)->setAutoSize(true);
        }

        $fileName = 'dashboard_' . $period . '_' . now()->format('Y-m-d_His') . '.xlsx';
        return new StreamedResponse(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
        ]);
    }

    protected function exportDashboardToPdf(array $data, string $period): \Illuminate\Http\Response
    {
        $periodLabel = $period === 'month' ? 'Месяц' : 'Неделя';
        $html = view('reports.dashboard-pdf', [
            'stats'       => $data['stats'],
            'daily_stats' => $data['daily_stats'],
            'managers'    => $data['managers'],
            'period'      => $periodLabel,
            'generated'   => now()->format('d.m.Y H:i'),
        ])->render();
        $pdf = app('dompdf.wrapper')->loadHTML($html);
        $fileName = 'dashboard_' . $period . '_' . now()->format('Y-m-d_His') . '.pdf';
        return $pdf->download($fileName);
    }
}