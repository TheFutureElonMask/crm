<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\ReportExportService;
use Illuminate\Console\Command;

/**
 * Тест генерации отчёта: экспорт в Excel или PDF из консоли.
 * Периоды: today, week, month, 3months. Для month и 3months укажите --month=YYYY-MM.
 * Примеры:
 *   php artisan report:export 1 --format=excel --period=today
 *   php artisan report:export 1 --format=pdf --period=week
 *   php artisan report:export 1 --format=excel --period=month --month=2025-02
 *   php artisan report:export 1 --format=pdf --period=3months --month=2025-02
 */
class ExportTimerReportCommand extends Command
{
    protected $signature = 'report:export
                            {manager_id : ID менеджера (user_id)}
                            {--format=excel : excel или pdf}
                            {--period=today : today, week, month, 3months}
                            {--month= : Для period=month или 3months: YYYY-MM}';

    protected $description = 'Сгенерировать отчёт по таймеру (Excel/PDF) за выбранный период';

    public function handle(ReportExportService $exportService): int
    {
        $managerId = (int) $this->argument('manager_id');
        $format = $this->option('format') ?: 'excel';
        $period = $this->option('period') ?: 'today';
        $month = $this->option('month');

        if (!in_array($format, ['excel', 'pdf'], true)) {
            $this->error('Формат должен быть excel или pdf.');
            return self::FAILURE;
        }
        if (!in_array($period, ReportExportService::PERIODS, true)) {
            $this->error('Период должен быть: today, week, month, 3months.');
            return self::FAILURE;
        }
        if (in_array($period, ['month', '3months'], true) && empty($month)) {
            $this->error('Для периода month и 3months укажите --month=YYYY-MM');
            return self::FAILURE;
        }

        $manager = User::where('id', $managerId)->where('role', 'manager')->first();
        if (!$manager) {
            $this->error("Менеджер с ID {$managerId} не найден.");
            return self::FAILURE;
        }

        $this->info("Генерация отчёта: {$manager->name}, период: {$period}, формат: {$format}");

        try {
            $suffix = $month ?: now()->format('Y-m-d');
            $ext = $format === 'excel' ? 'xlsx' : 'pdf';
            $path = storage_path('app/timer_report_' . $manager->id . '_' . $period . '_' . $suffix . '.' . $ext);

            if ($format === 'excel') {
                $exportService->timerReportToExcelFile($manager, $period, $path, $month);
            } else {
                $exportService->timerReportToPdfFile($manager, $period, $path, $month);
            }

            $this->info('Файл сохранён: ' . $path);
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Ошибка: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
