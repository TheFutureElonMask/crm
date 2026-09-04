<?php

namespace App\Console\Commands;

use App\Services\GreenApiGateways;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class GreenSetupWebhook extends Command
{
    protected $signature = 'green:setup-webhook {url? : Public base URL (or WEBHOOK_PUBLIC_URL in .env)}';

    protected $description = 'Configure all Green API instance webhooks for incoming WhatsApp messages';

    public function handle(GreenApiGateways $gateways): int
    {
        $base = rtrim($this->argument('url') ?: (string) env('WEBHOOK_PUBLIC_URL', ''), '/');

        if ($base === '') {
            $this->error('Provide URL argument or set WEBHOOK_PUBLIC_URL in .env');
            $this->line('Example: npx localtunnel --port 8000  →  php artisan green:setup-webhook https://xxx.loca.lt');

            return self::FAILURE;
        }
        $webhookUrl = $base.'/api/green/webhook';

        $configured = $gateways->all();
        if ($configured === []) {
            $this->error('No Green API gateways are configured in .env');

            return self::FAILURE;
        }

        $failed = false;
        foreach ($configured as $gateway) {
            $url = "{$gateway['host']}/waInstance{$gateway['id']}/setSettings/{$gateway['token']}";
            $this->info("Setting {$gateway['key']} ({$gateway['id']}): {$webhookUrl}");

            $response = Http::timeout(30)->acceptJson()->post($url, [
                'webhookUrl' => $webhookUrl,
                'incomingWebhook' => 'yes',
                'outgoingWebhook' => 'no',
                'stateWebhook' => 'yes',
            ]);

            if (! $response->successful()) {
                $failed = true;
                $this->error("{$gateway['key']} failed: {$response->status()} {$response->body()}");
            }
        }

        if ($failed) {
            return self::FAILURE;
        }

        $this->info('All Green API webhooks configured successfully.');

        return self::SUCCESS;
    }
}
