<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GreenApiMultiGatewayTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_green_api_instance_routes_lead_and_reply_to_its_manager(): void
    {
        Http::fake([
            'https://7101.api.greenapi.com/*' => Http::response(['idMessage' => 'reply-1']),
            'https://7102.api.greenapi.com/*' => Http::response(['idMessage' => 'reply-2']),
        ]);

        $managerOne = User::factory()->create(['email' => 'one@mfpto.kz', 'role' => 'manager']);
        $managerTwo = User::factory()->create(['email' => 'two@mfpto.kz', 'role' => 'manager']);

        config()->set('services.green_api.gateways', [
            'manager_1' => [
                'host' => 'https://7101.api.greenapi.com',
                'id' => '7101000001',
                'token' => 'token-one',
                'manager_email' => $managerOne->email,
            ],
            'manager_2' => [
                'host' => 'https://7102.api.greenapi.com',
                'id' => '7102000002',
                'token' => 'token-two',
                'manager_email' => $managerTwo->email,
            ],
        ]);

        $this->postJson('/api/green/webhook', $this->payload('7101000001', 'message-1'))->assertOk();
        $this->postJson('/api/green/webhook', $this->payload('7102000002', 'message-2'))->assertOk();

        $this->assertDatabaseHas('leads', [
            'phone' => '77001234567',
            'green_api_instance_id' => '7101000001',
            'user_id' => $managerOne->id,
        ]);
        $this->assertDatabaseHas('leads', [
            'phone' => '77001234567',
            'green_api_instance_id' => '7102000002',
            'user_id' => $managerTwo->id,
        ]);

        $this->assertDatabaseCount('leads', 2);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'waInstance7101000001/sendMessage/token-one'));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'waInstance7102000002/sendMessage/token-two'));
    }

    public function test_unconfigured_instance_is_ignored(): void
    {
        config()->set('services.green_api.gateways', [
            'manager_1' => [
                'host' => 'https://7101.api.greenapi.com',
                'id' => '7101000001',
                'token' => 'token-one',
                'manager_email' => '',
            ],
        ]);

        $this->postJson('/api/green/webhook', $this->payload('9999999999', 'unknown'))->assertOk();

        $this->assertDatabaseCount('leads', 0);
    }

    private function payload(string $instanceId, string $messageId): array
    {
        return [
            'typeWebhook' => 'incomingMessageReceived',
            'instanceData' => ['idInstance' => $instanceId],
            'idMessage' => $messageId,
            'senderData' => [
                'chatId' => '77001234567@c.us',
                'senderName' => 'Test Client',
            ],
            'messageData' => [
                'typeMessage' => 'textMessage',
                'textMessageData' => ['textMessage' => 'Здравствуйте'],
            ],
        ];
    }
}
