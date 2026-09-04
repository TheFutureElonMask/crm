<?php

namespace App\Services;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Response;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Экспорт отчётов в Excel и PDF.
 * Периоды: today, week, month, 3months.
 */
class ReportExportService
{
    public const PERIOD_TODAY = 'today';
    public const PERIOD_WEEK = 'week';
    public const PERIOD_MONTH = 'month';
    public const PERIOD_3MONTHS = '3months';

    public const PERIODS = [self::PERIOD_TODAY, self::PERIOD_WEEK, self::PERIOD_MONTH, self::PERIOD_3MONTHS];

    public function __construct(
        protected ReportService $reportService
    ) {}

    /**
     * Диапазон дат по периоду. month обязателен для month и 3months (YYYY-MM).
     * @return array{0: Carbon, 1: Carbon, 2: string} [from, to, periodLabel]
     */
    public function getDateRangeForPeriod(string $period, ?string $month = null): array
    {
        $tz = config('app.timezone', 'UTC');
        $now = Carbon::now($tz);

        return match ($period) {
            self::PERIOD_TODAY => [
                $now->copy()->startOfDay(),
                $now->copy()->endOfDay(),
                'За день ' . $now->format('d.m.Y'),
            ],
            self::PERIOD_WEEK => [
                $now->copy()->subDays(6)->startOfDay(),
                $now->copy()->endOfDay(),
                'За неделю (' . $now->copy()->subDays(6)->format('d.m.Y') . ' — ' . $now->format('d.m.Y') . ')',
            ],
            self::PERIOD_MONTH => $this->monthRange($month, 1, $tz),
            self::PERIOD_3MONTHS => $this->monthRange($month, 3, $tz),
            default => throw new \InvalidArgumentException('Недопустимый период: ' . $period),
        };
    }

    private function monthRange(string $month, int $monthsCount, string $tz): array
    {
        $end = Carbon::createFromFormat('Y-m', $month, $tz)->endOfMonth();
        $start = $end->copy()->subMonths($monthsCount - 1)->startOfMonth();
        $label = $monthsCount === 1
            ? 'За месяц ' . $start->format('d.m.Y') . ' — ' . $end->format('d.m.Y')
            : 'За 3 месяца ' . $start->format('d.m.Y') . ' — ' . $end->format('d.m.Y');
        return [$start, $end, $label];
    }

    /**
     * Экспорт отчёта по таймеру в Excel.
     * period: today | week | month | 3months. month=YYYY-MM обязателен для month и 3months.
     */
    public function timerReportToExcel(User $manager, string $period, ?string $month = null): StreamedResponse
    {
        [$from, $to, $periodLabel] = $this->getDateRangeForPeriod($period, $month);
        $days = $this->reportService->timerRangeReport($manager->id, $from, $to);
        $totalWork = array_sum(array_column($days, 'work_seconds'));
        $totalBreak = array_sum(array_column($days, 'break_seconds'));

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Таймер');
        $sheet->setCellValue('A1', 'Отчёт по таймеру');
        $sheet->mergeCells('A1:D1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->setCellValue('A2', $manager->name);
        $sheet->setCellValue('A3', $periodLabel);
        $sheet->setCellValue('A5', 'Дата');
        $sheet->setCellValue('B5', 'Работа (ч:м)');
        $sheet->setCellValue('C5', 'Перерыв (ч:м)');
        $sheet->getStyle('A5:C5')->getFont()->setBold(true);
        $sheet->getStyle('A5:C5')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E2E8F0');
        $row = 6;
        foreach ($days as $day) {
            $sheet->setCellValue('A' . $row, $day['date']);
            $sheet->setCellValue('B' . $row, $this->secondsToHm($day['work_seconds']));
            $sheet->setCellValue('C' . $row, $this->secondsToHm($day['break_seconds']));
            $row++;
        }
        $row++;
        $sheet->setCellValue('A' . $row, 'Итого');
        $sheet->setCellValue('B' . $row, $this->secondsToHm($totalWork));
        $sheet->setCellValue('C' . $row, $this->secondsToHm($totalBreak));
        $sheet->getStyle('A' . $row . ':C' . $row)->getFont()->setBold(true);
        foreach (range('A', 'C') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $suffix = $month ?: $from->format('Y-m-d');
        $fileName = sprintf('timer_report_%s_%s_%s.xlsx', $manager->id, $period, $suffix);
        return new StreamedResponse(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
        ]);
    }

    /**
     * Сохранить отчёт в Excel в файл (для Artisan/тестов).
     */
    public function timerReportToExcelFile(User $manager, string $period, string $path, ?string $month = null): string
    {
        [$from, $to, $periodLabel] = $this->getDateRangeForPeriod($period, $month);
        $days = $this->reportService->timerRangeReport($manager->id, $from, $to);
        $totalWork = array_sum(array_column($days, 'work_seconds'));
        $totalBreak = array_sum(array_column($days, 'break_seconds'));
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Таймер');
        $sheet->setCellValue('A1', 'Отчёт по таймеру');
        $sheet->mergeCells('A1:D1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->setCellValue('A2', $manager->name);
        $sheet->setCellValue('A3', $periodLabel);
        $sheet->setCellValue('A5', 'Дата');
        $sheet->setCellValue('B5', 'Работа (ч:м)');
        $sheet->setCellValue('C5', 'Перерыв (ч:м)');
        $sheet->getStyle('A5:C5')->getFont()->setBold(true);
        $sheet->getStyle('A5:C5')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E2E8F0');
        $row = 6;
        foreach ($days as $day) {
            $sheet->setCellValue('A' . $row, $day['date']);
            $sheet->setCellValue('B' . $row, $this->secondsToHm($day['work_seconds']));
            $sheet->setCellValue('C' . $row, $this->secondsToHm($day['break_seconds']));
            $row++;
        }
        $row++;
        $sheet->setCellValue('A' . $row, 'Итого');
        $sheet->setCellValue('B' . $row, $this->secondsToHm($totalWork));
        $sheet->setCellValue('C' . $row, $this->secondsToHm($totalBreak));
        $sheet->getStyle('A' . $row . ':C' . $row)->getFont()->setBold(true);
        $writer = new Xlsx($spreadsheet);
        $writer->save($path);
        return $path;
    }

    /**
     * Экспорт отчёта по таймеру в PDF.
     * period: today | week | month | 3months. month=YYYY-MM для month и 3months.
     */
    public function timerReportToPdf(User $manager, string $period, ?string $month = null): Response
    {
        [$from, $to, $periodLabel] = $this->getDateRangeForPeriod($period, $month);
        $days = $this->reportService->timerRangeReport($manager->id, $from, $to);
        $totalWork = array_sum(array_column($days, 'work_seconds'));
        $totalBreak = array_sum(array_column($days, 'break_seconds'));
        foreach ($days as &$d) {
            $d['work_hm'] = $this->secondsToHm($d['work_seconds']);
            $d['break_hm'] = $this->secondsToHm($d['break_seconds']);
        }
        unset($d);
        $html = view('reports.timer-monthly-pdf', [
            'manager'     => $manager,
            'periodLabel' => $periodLabel,
            'from'        => $from->format('d.m.Y'),
            'to'          => $to->format('d.m.Y'),
            'days'        => $days,
            'total_work'  => $this->secondsToHm($totalWork),
            'total_break' => $this->secondsToHm($totalBreak),
        ])->render();
        $pdf = app('dompdf.wrapper')->loadHTML($html);
        $suffix = $month ?: $from->format('Y-m-d');
        $fileName = sprintf('timer_report_%s_%s_%s.pdf', $manager->id, $period, $suffix);
        return $pdf->download($fileName);
    }

    /**
     * Сохранить отчёт в PDF в файл (для Artisan/тестов).
     */
    public function timerReportToPdfFile(User $manager, string $period, string $path, ?string $month = null): string
    {
        [$from, $to, $periodLabel] = $this->getDateRangeForPeriod($period, $month);
        $days = $this->reportService->timerRangeReport($manager->id, $from, $to);
        $totalWork = array_sum(array_column($days, 'work_seconds'));
        $totalBreak = array_sum(array_column($days, 'break_seconds'));
        foreach ($days as &$d) {
            $d['work_hm'] = $this->secondsToHm($d['work_seconds']);
            $d['break_hm'] = $this->secondsToHm($d['break_seconds']);
        }
        unset($d);
        $html = view('reports.timer-monthly-pdf', [
            'manager'     => $manager,
            'periodLabel' => $periodLabel,
            'from'        => $from->format('d.m.Y'),
            'to'          => $to->format('d.m.Y'),
            'days'        => $days,
            'total_work'  => $this->secondsToHm($totalWork),
            'total_break' => $this->secondsToHm($totalBreak),
        ])->render();
        $pdf = app('dompdf.wrapper')->loadHTML($html);
        $pdf->save($path);
        return $path;
    }

    protected function secondsToHm(int $seconds): string
    {
        $h = (int) floor($seconds / 3600);
        $m = (int) floor(($seconds % 3600) / 60);
        return sprintf('%dч %02dм', $h, $m);
    }
}
