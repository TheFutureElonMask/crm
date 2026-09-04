<?php

namespace App\Console\Commands;

use App\Services\GreenApiGateways;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class GreenClearWebhook extends Command
{
    protected $signature = 'green:clear-webhook';

    protected $description = 'Clear Green API webhook URL (required before green:poll)';

    public function handle(GreenApiGateways $gateways): int
    {
        $configured = $gateways->all();
        if ($configured === []) {
            $this->error('No Green API gateways are configured in .env');

            return self::FAILURE;
        }

        $failed = false;
        foreach ($configured as $gateway) {
            $url = "{$gateway['host']}/waInstance{$gateway['id']}/setSettings/{$gateway['token']}";
            $this->info("Clearing {$gateway['key']} ({$gateway['id']})...");

            $response = Http::timeout(30)->acceptJson()->post($url, [
                'webhookUrl' => '',
                'incomingWebhook' => 'yes',
                'outgoingWebhook' => 'no',
                'stateWebhook' => 'no',
                'outgoingMessageWebhook' => 'no',
                'outgoingAPIMessageWebhook' => 'no',
                'deviceWebhook' => 'no',
                'statusInstanceWebhook' => 'no',
            ]);

            if (! $response->successful()) {
                $failed = true;
                $this->error("{$gateway['key']} failed: {$response->status()} {$response->body()}");
            }
        }

        if ($failed) {
            return self::FAILURE;
        }
        $this->info('All webhooks cleared. Start one poller per instance with green:poll --instance=ID.');

        return self::SUCCESS;
    }
}
