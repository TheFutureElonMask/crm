<?php

namespace App\Services;

use App\Models\ManagerTimerEvent;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Сервис генерации отчётов: таймер, сделки, аналитика.
 */
class ReportService
{
    protected string $timezone;

    public function __construct()
    {
        $this->timezone = config('app.timezone', 'UTC');
    }

    /**
     * Текущий статус менеджера по последнему событию таймера.
     */
    public function resolveTimerStatus(int $userId, string $fallbackStatus = 'offline'): string
    {
        $lastEvent = ManagerTimerEvent::where('user_id', $userId)
            ->orderByDesc('happened_at')
            ->first();

        if (!$lastEvent) {
            return 'offline';
        }

        return match ($lastEvent->action) {
            'start_work', 'break_end' => 'online',
            'break_start' => 'break',
            'end_day' => 'offline',
            default => $fallbackStatus,
        };
    }

    /**
     * Отчёт по таймеру за произвольный период для одного пользователя.
     * Возвращает массив дней: [ ['date' => 'd.m.Y', 'work_seconds' => int, 'break_seconds' => int], ... ]
     */
    public function timerRangeReport(int $userId, Carbon $fromStart, Carbon $toEnd): array
    {
        $result = [];
        $cursor = $fromStart->copy()->startOfDay();
        $lastDay = $toEnd->copy()->startOfDay();

        while ($cursor->lte($lastDay)) {
            $dayStart = $cursor->copy()->startOfDay();
            $dayEnd = $cursor->copy()->endOfDay();
            $calcNow = $dayStart->isToday() ? Carbon::now($this->timezone) : $dayEnd;

            $events = ManagerTimerEvent::where('user_id', $userId)
                ->whereBetween('happened_at', [$dayStart, $dayEnd])
                ->orderBy('happened_at')
                ->get();

            $initialState = $this->resolveStateAtDayStart($userId, $dayStart);
            $stats = $this->buildDayStats($events, $calcNow, $dayStart, $initialState);

            $result[] = [
                'date' => $dayStart->format('d.m.Y'),
                'work_seconds' => $stats['work_seconds'],
                'break_seconds' => $stats['break_seconds'],
            ];

            $cursor->addDay();
        }

        return $result;
    }

    /**
     * Недельный отчёт по таймеру (последние 7 дней, сегодня первым).
     */
    public function timerWeeklyReport(int $userId): array
    {
        $todayStart = Carbon::now($this->timezone)->startOfDay();
        $from = $todayStart->copy()->subDays(6);
        $to = $todayStart->copy()->endOfDay();
        $days = $this->timerRangeReport($userId, $from, $to);
        return array_reverse($days);
    }

    /**
     * Месячный отчёт по таймеру для пользователя.
     */
    public function timerMonthlyReport(int $userId, Carbon $monthStart): array
    {
        $monthEnd = $monthStart->copy()->endOfMonth();
        return $this->timerRangeReport($userId, $monthStart, $monthEnd);
    }

    /**
     * Статистика за один день: work_seconds, break_seconds.
     */
    public function dayStatsForUser(int $userId, Carbon $dayStart, ?Carbon $now = null): array
    {
        $now = $now ?? Carbon::now($this->timezone);
        $dayEnd = $dayStart->copy()->endOfDay();
        $events = ManagerTimerEvent::where('user_id', $userId)
            ->whereBetween('happened_at', [$dayStart, $dayEnd])
            ->orderBy('happened_at')
            ->get();
        $initialState = $this->resolveStateAtDayStart($userId, $dayStart);
        return $this->buildDayStats($events, $now, $dayStart, $initialState);
    }

    /**
     * Форматирование событий таймера для API (метки, время).
     */
    public function formatTimerEvents(Collection $events): array
    {
        $labels = [
            'start_work' => 'Начало смены',
            'break_start' => 'Перерыв',
            'break_end' => 'Возврат',
            'end_day' => 'Завершение',
        ];

        return $events->map(function ($event) use ($labels) {
            $action = $event->action;
            return [
                'id' => $event->id,
                'action' => $action,
                'label' => $labels[$action] ?? $action,
                'time' => Carbon::parse($event->happened_at)->format('H:i'),
                'happened_at' => Carbon::parse($event->happened_at)->toIso8601String(),
            ];
        })->values()->all();
    }

    protected function resolveStateAtDayStart(int $userId, Carbon $dayStart): string
    {
        $lastEvent = ManagerTimerEvent::where('user_id', $userId)
            ->where('happened_at', '<', $dayStart)
            ->orderByDesc('happened_at')
            ->first();

        if (!$lastEvent) {
            return 'idle';
        }

        return match ($lastEvent->action) {
            'start_work', 'break_end' => 'work',
            'break_start' => 'break',
            default => 'idle',
        };
    }

    protected function buildDayStats($events, Carbon $now, Carbon $dayStart, string $initialState): array
    {
        $workSeconds = 0;
        $breakSeconds = 0;
        $workStartedAt = $initialState === 'work' ? $dayStart->copy() : null;
        $breakStartedAt = $initialState === 'break' ? $dayStart->copy() : null;

        foreach ($events as $event) {
            $at = Carbon::parse($event->happened_at);

            switch ($event->action) {
                case 'start_work':
                    $workStartedAt = $at;
                    $breakStartedAt = null;
                    break;
                case 'break_start':
                    if ($workStartedAt) {
                        $workSeconds += max(0, $workStartedAt->diffInSeconds($at));
                        $workStartedAt = null;
                    }
                    $breakStartedAt = $at;
                    break;
                case 'break_end':
                    if ($breakStartedAt) {
                        $breakSeconds += max(0, $breakStartedAt->diffInSeconds($at));
                        $breakStartedAt = null;
                    }
                    $workStartedAt = $at;
                    break;
                case 'end_day':
                    if ($workStartedAt) {
                        $workSeconds += max(0, $workStartedAt->diffInSeconds($at));
                        $workStartedAt = null;
                    }
                    if ($breakStartedAt) {
                        $breakSeconds += max(0, $breakStartedAt->diffInSeconds($at));
                        $breakStartedAt = null;
                    }
                    break;
            }
        }

        if ($workStartedAt) {
            $workSeconds += max(0, $workStartedAt->diffInSeconds($now));
        }
        if ($breakStartedAt) {
            $breakSeconds += max(0, $breakStartedAt->diffInSeconds($now));
        }

        return [
            'work_seconds' => $workSeconds,
            'break_seconds' => $breakSeconds,
        ];
    }
}
