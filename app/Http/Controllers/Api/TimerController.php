<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ManagerTimerEvent;
use App\Models\User;
use App\Services\ReportExportService;
use App\Services\ReportService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class TimerController extends Controller
{
    public function __construct(
        protected ReportService $reportService,
        protected ReportExportService $reportExportService
    ) {}

    public function adminMonthlyReport(Request $request, User $manager)
    {
        $user = $request->user();
        if ($user->role !== 'admin') {
            return response()->json(['message' => 'Доступ только для администратора'], 403);
        }
        if ($manager->role !== 'manager') {
            return response()->json(['message' => 'Отчет доступен только по менеджеру'], 422);
        }

        $request->validate([
            'month' => 'nullable|regex:/^\d{4}-\d{2}$/',
        ]);

        $monthParam = $request->query('month'); // YYYY-MM
        $monthStart = $monthParam
            ? Carbon::createFromFormat('Y-m', $monthParam)->startOfMonth()
            : now()->startOfMonth();
        $monthEnd = $monthStart->copy()->endOfMonth();

        $report = $this->reportService->timerRangeReport($manager->id, $monthStart, $monthEnd);

        return response()->json([
            'manager' => [
                'id' => $manager->id,
                'name' => $manager->name,
                'email' => $manager->email,
            ],
            'month' => $monthStart->format('Y-m'),
            'from' => $monthStart->format('d.m.Y'),
            'to' => $monthEnd->format('d.m.Y'),
            'days' => $report,
            'total_work_seconds' => collect($report)->sum('work_seconds'),
            'total_break_seconds' => collect($report)->sum('break_seconds'),
        ]);
    }

    /**
     * Экспорт отчёта по таймеру в Excel или PDF.
     * Периоды: today, week, month, 3months. Для month и 3months обязателен month=YYYY-MM.
     * Примеры:
     *   ?format=excel&period=today
     *   ?format=pdf&period=week
     *   ?format=excel&period=month&month=2025-02
     *   ?format=pdf&period=3months&month=2025-02
     */
    public function exportReport(Request $request, User $manager)
    {
        $user = $request->user();
        if ($user->role !== 'admin') {
            return response()->json(['message' => 'Доступ только для администратора'], 403);
        }
        if ($manager->role !== 'manager') {
            return response()->json(['message' => 'Отчет доступен только по менеджеру'], 422);
        }

        $request->validate([
            'format' => 'required|string|in:excel,pdf',
            'period' => 'required|string|in:today,week,month,3months',
            'month'  => 'nullable|regex:/^\d{4}-\d{2}$/',
        ]);

        $format = $request->query('format');
        $period = $request->query('period');
        $month = $request->query('month');

        if (in_array($period, ['month', '3months'], true) && empty($month)) {
            return response()->json(['message' => 'Для периода month и 3months укажите month=YYYY-MM'], 422);
        }

        if ($format === 'excel') {
            return $this->reportExportService->timerReportToExcel($manager, $period, $month);
        }
        return $this->reportExportService->timerReportToPdf($manager, $period, $month);
    }

    public function myToday(Request $request)
    {
        $user = $request->user();
        $now = now();
        $todayStart = $now->copy()->startOfDay();
        $todayEnd = $now->copy()->endOfDay();

        $events = ManagerTimerEvent::where('user_id', $user->id)
            ->whereBetween('happened_at', [$todayStart, $todayEnd])
            ->orderBy('happened_at')
            ->get();

        $stats = $this->reportService->dayStatsForUser($user->id, $todayStart, $now);

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'role' => $user->role,
                'status' => $this->reportService->resolveTimerStatus($user->id, $user->status),
            ],
            'today_work_time' => $stats['work_seconds'],
            'today_break_time' => $stats['break_seconds'],
            'today_events' => $this->reportService->formatTimerEvents($events),
        ]);
    }

    public function action(Request $request)
    {
        $validated = $request->validate([
            'action' => 'required|string|in:start_work,break_start,break_end,end_day',
        ]);

        $user = $request->user();
        $action = $validated['action'];
        $currentStatus = $this->reportService->resolveTimerStatus($user->id, $user->status);

        // Синхронизируем users.status с фактическим статусом по событиям,
        // чтобы проверки и UI всегда работали одинаково.
        if ($user->status !== $currentStatus) {
            $user->update(['status' => $currentStatus]);
            $user->refresh();
        }

        if ($action === 'start_work' && $currentStatus !== 'offline') {
            return response()->json(['message' => 'Смена уже начата'], 422);
        }
        if ($action === 'break_start' && $currentStatus !== 'online') {
            return response()->json(['message' => 'Перерыв можно начать только во время смены'], 422);
        }
        if ($action === 'break_end' && $currentStatus !== 'break') {
            return response()->json(['message' => 'Вы сейчас не на перерыве'], 422);
        }
        if ($action === 'end_day' && !in_array($currentStatus, ['online', 'break'], true)) {
            return response()->json(['message' => 'Смена еще не начата'], 422);
        }

        ManagerTimerEvent::create([
            'user_id' => $user->id,
            'action' => $action,
            'happened_at' => now(),
        ]);

        $nextStatus = match ($action) {
            'start_work', 'break_end' => 'online',
            'break_start' => 'break',
            'end_day' => 'offline',
        };

        $user->update(['status' => $nextStatus]);

        return $this->myToday($request);
    }

    public function adminToday(Request $request)
    {
        $user = $request->user();
        if ($user->role !== 'admin') {
            return response()->json(['message' => 'Доступ только для администратора'], 403);
        }
        $perPage = (int) $request->query('per_page', 6);
        $perPage = max(1, min($perPage, 30));

        $paginator = User::where('role', 'manager')->orderBy('name')->paginate($perPage);
        $managers = collect($paginator->items());
        $managerIds = $managers->pluck('id');

        $tz = config('app.timezone', 'UTC');
        $now = Carbon::now($tz);
        $todayStart = $now->copy()->startOfDay();
        $todayEnd = $now->copy()->endOfDay();

        $eventsByManager = ManagerTimerEvent::whereIn('user_id', $managerIds)
            ->whereBetween('happened_at', [$todayStart, $todayEnd])
            ->orderBy('happened_at')
            ->get()
            ->groupBy('user_id');

        $result = $managers->map(function ($manager) use ($eventsByManager, $now, $todayStart) {
            $events = $eventsByManager->get($manager->id) ?? collect();
            $stats = $this->reportService->dayStatsForUser($manager->id, $todayStart, $now);

            return [
                'id' => $manager->id,
                'name' => $manager->name,
                'email' => $manager->email,
                'role' => $manager->role,
                'status' => $this->reportService->resolveTimerStatus($manager->id, $manager->status),
                'today_work_time' => $stats['work_seconds'],
                'today_break_time' => $stats['break_seconds'],
                'today_events' => $this->reportService->formatTimerEvents($events),
                'weekly_report' => $this->reportService->timerWeeklyReport($manager->id),
            ];
        });

        return response()->json([
            'data' => $result->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

}
