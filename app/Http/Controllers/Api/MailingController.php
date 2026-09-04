<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendWhatsappMessage;
use App\Models\Lead;
use App\Models\Mailing;
use App\Services\GreenApiGateways;
use Illuminate\Http\Request;

class MailingController extends Controller
{
    public function store(Request $request, GreenApiGateways $gateways)
    {
        $validated = $request->validate([
            'subject' => 'required|string|max:255',
            'message' => 'required|string',
            'status_filter' => 'nullable|string',
        ]);

        $mailing = Mailing::create([
            'subject' => $validated['subject'],
            'message' => $validated['message'],
            'status' => 'processing',
        ]);

        $query = Lead::query();

        if ($request->filled('status_filter')) {
            $query->where('status', $request->status_filter);
        }

        $leads = $query->get();

        if ($leads->isEmpty()) {
            return response()->json(['message' => 'Нет подходящих контактов для рассылки'], 404);
        }

        $mailing->update(['total_count' => $leads->count()]);

        foreach ($leads as $index => $lead) {
            $instanceId = $lead->green_api_instance_id;
            if (! $instanceId) {
                $instanceId = $gateways->findByManagerEmail($lead->manager?->email)['id'] ?? null;
            }

            SendWhatsappMessage::dispatch(
                $lead->phone,
                $validated['message'],
                $mailing,
                $instanceId
            )
                ->delay(now()->addSeconds($index * 10));
        }

        return response()->json([
            'message' => 'Рассылка запущена',
            'total_contacts' => $leads->count(),
            'estimated_time' => ($leads->count() * 10).' секунд',
        ]);
    }

    public function destroyAll()
    {
        Mailing::truncate();

        return response()->json(['message' => 'История очищена']);
    }

    public function index()
    {
        return response()->json(Mailing::orderBy('created_at', 'desc')->get());
    }
}
