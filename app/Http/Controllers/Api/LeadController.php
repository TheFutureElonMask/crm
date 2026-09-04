<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon as SupportCarbon;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LeadController extends Controller
{
    public function export(Request $request): StreamedResponse|\Illuminate\Http\Response|\Illuminate\Http\JsonResponse
    {
        $request->validate(['format' => 'required|string|in:excel,pdf']);

        $query = $this->buildLeadsQueryForExport($request);
        $format = $request->query('format');

        if ($format === 'excel') {
            return $this->exportLeadsToExcel($query->get());
        }
        set_time_limit(120);
        @ini_set('memory_limit', '256M');
        $totalCount = (clone $query)->count();
        $leads = $query->limit(200)->get();
        try {
            return $this->exportLeadsToPdf($leads, $totalCount);
        } catch (\Throwable $e) {
            \Log::error('Leads PDF export failed', ['exception' => $e->getMessage()]);
            return response()->json(['message' => 'Ошибка генерации PDF: ' . $e->getMessage()], 500);
        }
    }

    protected function buildLeadsQueryForExport(Request $request): Builder
    {
        $user = auth()->user();
        $query = Lead::query()
            ->select('id', 'title', 'client_name', 'phone', 'price', 'status', 'user_id', 'created_at', 'description')
            ->with('manager:id,name');

        if ($user->role !== 'admin') {
            $query->where('user_id', $user->id);
        } else {
            $query->when($request->filled('user_id'), fn($q) => $q->where('user_id', $request->user_id));
        }
        $search = $request->input('search');
        $query->when($search, function ($q) use ($search) {
            return $q->where(function ($sub) use ($search) {
                $sub->where('title', 'like', "%{$search}%")
                    ->orWhere('client_name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        });
        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }
        if ($request->filled('date')) {
            $date = $request->date;
            $query->whereBetween('created_at', [$date . ' 00:00:00', $date . ' 23:59:59']);
        }
        $query->orderBy('created_at', 'desc');
        return $query;
    }

    protected function exportLeadsToExcel($leads): StreamedResponse
    {
        $statusLabels = ['new' => 'Новый', 'processing' => 'В работе', 'won' => 'Успешно', 'rejected' => 'Отказ'];
        $rows = $leads->map(function ($lead, $i) use ($statusLabels) {
            return [
                '№' => $i + 1,
                'Сделка' => $lead->title,
                'Клиент' => $lead->client_name,
                'Телефон' => $lead->phone ?? '—',
                'Сумма (₸)' => (float) $lead->price,
                'Статус' => $statusLabels[$lead->status] ?? $lead->status,
                'Менеджер' => $lead->manager?->name ?? '—',
                'Дата' => $lead->created_at ? $lead->created_at->format('d.m.Y') : '—',
            ];
        })->all();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Сделки');
        $headers = $rows ? array_keys($rows[0]) : [];
        $data = $rows ? array_merge([$headers], array_map('array_values', $rows)) : [$headers];
        $sheet->fromArray($data, null, 'A1');
        $sheet->getStyle('A1:H1')->getFont()->setBold(true);
        $sheet->getStyle('A1:H1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E2E8F0');
        foreach (range('A', 'H') as $c) {
            $sheet->getColumnDimension($c)->setAutoSize(true);
        }

        $fileName = 'leads_' . now()->format('Y-m-d_His') . '.xlsx';
        return new StreamedResponse(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
        ]);
    }

    protected function exportLeadsToPdf($leads, int $totalCount = 0): \Illuminate\Http\Response
    {
        $statusLabels = ['new' => 'Новый', 'processing' => 'В работе', 'won' => 'Успешно', 'rejected' => 'Отказ'];
        $rows = $leads->map(function ($lead) use ($statusLabels) {
            return [
                'title' => $lead->title ?? '—',
                'client_name' => $lead->client_name ?? '—',
                'phone' => $lead->phone ?? '—',
                'price' => number_format((float) ($lead->price ?? 0), 0, '', ' ') . ' ₸',
                'status' => $statusLabels[$lead->status ?? 'new'] ?? $lead->status,
                'manager' => $lead->manager?->name ?? '—',
                'date' => $lead->created_at ? $lead->created_at->format('d.m.Y') : '—',
            ];
        })->all();

        $limitNote = $totalCount > $leads->count() ? " (в PDF показаны первые {$leads->count()} из {$totalCount})" : '';
        $html = view('reports.leads-pdf', ['leads' => $rows, 'generated' => now()->format('d.m.Y H:i'), 'limit_note' => $limitNote])->render();
        $pdf = app('dompdf.wrapper')->loadHTML($html);
        $fileName = 'leads_' . now()->format('Y-m-d_His') . '.pdf';
        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }

public function index(Request $request)
    {
        $user = auth()->user();
        $search = $request->input('search');
        $statusFilter = $request->input('status');
        $perPage = $request->input('per_page', 20);

        $query = Lead::query()
            ->select('id', 'title', 'client_name', 'phone', 'price', 'status', 'user_id', 'created_at', 'description')
            ->with([
                'manager:id,name', 
                'activities' => fn($q) => $q->select('id', 'lead_id', 'description', 'created_at', 'type')
                    ->latest()
                    ->limit(1)
            ]);

        // --- ЛОГИКА ДОСТУПА ---
        if ($user->role !== 'admin') {
            // Менеджер видит свои лиды + те, которые еще никем не взяты (user_id IS NULL)
            $query->where(function($q) use ($user) {
                $q->where('user_id', $user->id)
                  ->orWhereNull('user_id');
            });
        } else {
            // Админ видит всё, но может фильтровать по конкретному менеджеру
            $query->when($request->filled('user_id'), function ($q) use ($request) {
                // Если передали 'unassigned', покажем только свободные
                if ($request->user_id === 'unassigned') {
                    return $q->whereNull('user_id');
                }
                return $q->where('user_id', $request->user_id);
            });
        }

        // --- ПОИСК ---
        $query->when($search, function ($q) use ($search) {
            return $q->where(function($sub) use ($search) {
                $sub->where('title', 'like', "%{$search}%")
                    ->orWhere('client_name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        });

        // --- ФИЛЬТР ПО СТАТУСУ ---
        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        // --- ФИЛЬТР ПО ДАТАМ ---
        if ($request->filled('date')) {
            $date = $request->date;
            $query->whereBetween('created_at', [$date . ' 00:00:00', $date . ' 23:59:59']);
        }

        $query->orderBy('created_at', 'desc');

        if ($request->boolean('export')) {
            return response()->json(['items' => $query->get()]);
        }

        $leads = $query->paginate($perPage);

        return response()->json([
            'items' => $leads->items(),
            'meta' => [
                'current_page' => $leads->currentPage(),
                'last_page'    => $leads->lastPage(),
                'total'        => $leads->total(),
                'per_page'     => $leads->perPage(),
            ]
        ]);
    }
    public function take(Lead $lead)
    {
        $user = auth()->user();

        if ($lead->user_id !== null && $lead->user_id !== $user->id) {
            return response()->json([
                'message' => 'Этот лид уже взял другой менеджер: ' . ($lead->manager->name ?? 'Неизвестно')
            ], 422);
        }

        $lead->update([
            'user_id' => $user->id,
            'status'  => 'processing',
        ]);

        $lead->activities()->create([
            'user_id' => $user->id,
            'description' => "Менеджер {$user->name} взял лид в работу",
            'type' => 'system'
        ]);

        return response()->json([
            'message' => 'Лид успешно закреплен за вами',
            'lead' => $lead->load('manager')
        ]);
    }
    public function store(Request $request)
    {
        $validated = $request->validate([
            'client_name' => 'required|string',
            'phone'       => 'required|string',
            'title'       => 'required|string',
            'description' => 'required|string',
            'price'       => 'numeric',
            'user_id'     => 'nullable|exists:users,id',
        ]);

        $lead = Lead::create($validated);

        return response()->json($lead, 201);
    }

    public function completeAction(Request $request, Lead $lead)
    {
        $validated = $request->validate([
            'description' => 'required|string',    
            'next_date' => 'nullable|date',    
            'status' => 'nullable|string',    
        ]);

        $lead->activities()->create([
            'user_id' => auth()->id(),
            'type' => 'note',
            'description' => "Действие выполнено: " . $validated['description'],
        ]);

        $lead->update([
            'next_action_at' => $validated['next_date'] ?? null,
            'status' => $validated['status'] ?? $lead->status,
        ]);

        return response()->json(['message' => 'Контакт зафиксирован, лид обновлен']);
    }
    public function update(Request $request, Lead $lead)
    {
        $validated = $request->validate([
            'title'       => 'sometimes|string|max:500',
            'client_name' => 'sometimes|string|max:255',
            'phone'       => 'sometimes|nullable|string|max:50',
            'email'       => 'sometimes|nullable|email|max:255',
            'description'=> 'sometimes|nullable|string',
            'price'       => 'sometimes|numeric|min:0',
            'status'      => 'sometimes|string|in:new,processing,won,rejected',
        ]);

        $lead->update($validated);

        return response()->json($lead->load('manager'));
    }

    public function show(Lead $lead)
    {
        return response()->json($lead->load('manager'));
    }
}