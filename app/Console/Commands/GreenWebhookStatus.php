<?php

namespace App\Console\Commands;

use App\Services\GreenApiGateways;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class GreenWebhookStatus extends Command
{
    protected $signature = 'green:webhook-status';

    protected $description = 'Show current Green API webhook settings';

    public function handle(GreenApiGateways $gateways): int
    {
        $configured = $gateways->all();
        if ($configured === []) {
            $this->error('No Green API gateways are configured in .env');

            return self::FAILURE;
        }

        $expected = rtrim((string) env('WEBHOOK_PUBLIC_URL', ''), '/').'/api/green/webhook';
        $failed = false;

        foreach ($configured as $gateway) {
            $response = Http::timeout(20)->get("{$gateway['host']}/waInstance{$gateway['id']}/getSettings/{$gateway['token']}");
            if (! $response->successful()) {
                $failed = true;
                $this->error("{$gateway['key']} ({$gateway['id']}) failed: {$response->status()}");

                continue;
            }

            $data = $response->json();
            $actual = (string) ($data['webhookUrl'] ?? '');
            $this->line("{$gateway['key']} ({$gateway['id']}): {$actual}");

            if ($expected !== '/api/green/webhook' && $actual !== $expected) {
                $failed = true;
                $this->warn("Expected: {$expected}");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
