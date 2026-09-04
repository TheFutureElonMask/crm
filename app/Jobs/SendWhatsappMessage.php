<?php

namespace App\Jobs;

use App\Models\Mailing;
use App\Services\GreenApiGateways;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendWhatsappMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $backoff = 10;

    protected $phone;

    protected $message;

    protected $mailing;

    protected $instanceId;

    public function __construct($phone, $message, Mailing $mailing, ?string $instanceId = null)
    {
        $this->phone = $phone;
        $this->message = $message;
        $this->mailing = $mailing;
        $this->instanceId = $instanceId;
    }

    public function handle(GreenApiGateways $gateways): void
    {
        $cleanPhone = preg_replace('/[^0-9]/', '', $this->phone);

        if ($cleanPhone === '') {
            Log::warning('WA mailing skipped: empty phone', ['mailing_id' => $this->mailing->id]);

            return;
        }

        $response = Http::timeout(30)
            ->withOptions(['verify' => (bool) config('services.green_api.verify_ssl', true)])
            ->post($this->getApiUrl($gateways), [
                'chatId' => "{$cleanPhone}@c.us",
                'message' => $this->message,
            ]);

        if ($response->successful()) {
            $this->mailing->increment('sent_count');

            if ($this->mailing->where('id', $this->mailing->id)
                ->whereColumn('sent_count', '>=', 'total_count')
                ->exists()) {
                $this->mailing->update(['status' => 'completed']);
            }
        } else {
            Log::error("WA API ERROR [{$cleanPhone}]: ".$response->status());
            if ($response->serverError()) {
                throw new \Exception('Green API Server Error');
            }
        }
    }

    protected function getApiUrl(GreenApiGateways $gateways): string
    {
        $gateway = $gateways->requireByInstance($this->instanceId);
        $host = $gateway['host'];
        $idInstance = $gateway['id'];
        $apiToken = $gateway['token'];

        return "{$host}/waInstance{$idInstance}/sendMessage/{$apiToken}";
    }
}
