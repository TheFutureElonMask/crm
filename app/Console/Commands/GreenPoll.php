<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\BotController;
use App\Services\GreenApiGateways;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GreenPoll extends Command
{
    protected $signature = 'green:poll {--instance= : Green API instance ID} {--timeout=30 : receiveTimeout in seconds (5-60)}';

    protected $description = 'Poll Green API for incoming WhatsApp messages (no public URL needed)';

    public function handle(BotController $bot, GreenApiGateways $gateways): int
    {
        $configured = $gateways->all();
        $instanceOption = trim((string) $this->option('instance'));
        $gateway = $instanceOption !== '' ? $gateways->findByInstance($instanceOption) : (count($configured) === 1 ? reset($configured) : null);

        if (! $gateway) {
            $this->error('Specify one configured instance: php artisan green:poll --instance=ID');

            return self::FAILURE;
        }
        $host = $gateway['host'];
        $id = $gateway['id'];
        $token = $gateway['token'];

        $timeout = max(5, min(60, (int) $this->option('timeout')));

        $this->info("Polling Green API instance {$id} (timeout={$timeout}s). Ctrl+C to stop.");
        $this->line('If you see "custom webhook url is set", run: php artisan green:clear-webhook');

        while (true) {
            $receiveUrl = "{$host}/waInstance{$id}/receiveNotification/{$token}";

            try {
                $response = Http::timeout($timeout + 15)->get($receiveUrl, [
                    'receiveTimeout' => $timeout,
                ]);
            } catch (\Throwable $e) {
                $this->warn('Request failed: '.$e->getMessage());
                sleep(5);

                continue;
            }

            if (! $response->successful()) {
                $body = $response->body();
                if (str_contains($body, 'custom webhook url is set')) {
                    $this->error('Webhook is still set. Run: php artisan green:clear-webhook');

                    return self::FAILURE;
                }
                $this->warn("receiveNotification HTTP {$response->status()}: {$body}");
                sleep(5);

                continue;
            }

            $data = $response->json();
            if (empty($data) || ! is_array($data)) {
                continue;
            }

            $receiptId = $data['receiptId'] ?? null;
            $payload = $data['body'] ?? null;

            if (! is_array($payload) || $receiptId === null) {
                continue;
            }

            if (filter_var(env('BOT_DEBUG', false), FILTER_VALIDATE_BOOLEAN)) {
                Log::info('GREEN poll received', [
                    'receiptId' => $receiptId,
                    'typeWebhook' => $payload['typeWebhook'] ?? null,
                ]);
                $this->line('Notification: '.($payload['typeWebhook'] ?? 'unknown'));
            }

            try {
                $bot->handleGreenPayload($payload, $id);
            } catch (\Throwable $e) {
                Log::error('GREEN poll process error', ['err' => $e->getMessage()]);
                $this->error('Process error: '.$e->getMessage());
            }

            try {
                Http::timeout(20)->delete("{$host}/waInstance{$id}/deleteNotification/{$token}/{$receiptId}");
            } catch (\Throwable $e) {
                Log::warning('GREEN poll deleteNotification failed', [
                    'receiptId' => $receiptId,
                    'err' => $e->getMessage(),
                ]);
                $this->warn('deleteNotification failed, continuing: '.$e->getMessage());
            }
        }
    }
}
