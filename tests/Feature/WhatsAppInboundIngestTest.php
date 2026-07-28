<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminInbox;
use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use App\Models\Setting;
use App\Models\User;
use App\Services\Channels\ChannelInboxDiagnostics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WhatsAppInboundIngestTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function signed_whatsapp_text_webhook_stores_conversation(): void
    {
        $secret = 'wa-secret';
        config([
            'whatsapp.enabled' => true,
            'whatsapp.app_secret' => $secret,
            'whatsapp.access_token' => 'wa-token',
            'channels.ai_draft.require_phone' => false,
            'channels.ai_draft.min_confidence' => 0.99,
        ]);

        $body = json_encode([
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'WABA',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => [
                            'display_phone_number' => '15551234567',
                            'phone_number_id' => '123456789',
                        ],
                        'contacts' => [[
                            'profile' => ['name' => 'Nusrat'],
                            'wa_id' => '8801712345678',
                        ]],
                        'messages' => [[
                            'from' => '8801712345678',
                            'id' => 'wamid.TEXT1',
                            'timestamp' => (string) now()->timestamp,
                            'type' => 'text',
                            'text' => ['body' => 'আসসালামু আলাইকুম'],
                        ]],
                    ],
                ]],
            ]],
        ], JSON_THROW_ON_ERROR);

        $this->call(
            'POST',
            '/api/webhooks/whatsapp',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, $secret),
            ],
            $body,
        )->assertOk();

        $conversation = ChannelConversation::query()
            ->where('channel', ChannelConversation::CHANNEL_WHATSAPP)
            ->where('external_user_id', '8801712345678')
            ->first();

        $this->assertNotNull($conversation);
        $this->assertSame('Nusrat', $conversation->customer_name);
        $this->assertDatabaseHas('channel_messages', [
            'channel_conversation_id' => $conversation->id,
            'external_message_id' => 'wamid.TEXT1',
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => 'আসসালামু আলাইকুম',
        ]);

        $health = json_decode((string) Setting::getValue(ChannelInboxDiagnostics::WHATSAPP_HEALTH_KEY), true);
        $this->assertIsArray($health);
        $this->assertNotEmpty($health['last_received_at'] ?? null);
    }

    #[Test]
    public function interactive_button_replies_are_ingested(): void
    {
        $secret = 'wa-secret';
        config([
            'whatsapp.enabled' => true,
            'whatsapp.app_secret' => $secret,
            'channels.ai_draft.require_phone' => false,
            'channels.ai_draft.min_confidence' => 0.99,
        ]);

        $body = json_encode([
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'metadata' => ['phone_number_id' => '123'],
                        'messages' => [[
                            'from' => '8801699988877',
                            'id' => 'wamid.BTN1',
                            'timestamp' => (string) now()->timestamp,
                            'type' => 'interactive',
                            'interactive' => [
                                'type' => 'button_reply',
                                'button_reply' => [
                                    'id' => 'confirm',
                                    'title' => 'Yes, confirm',
                                ],
                            ],
                        ]],
                    ],
                ]],
            ]],
        ], JSON_THROW_ON_ERROR);

        $this->call(
            'POST',
            '/api/webhooks/whatsapp',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, $secret),
            ],
            $body,
        )->assertOk();

        $this->assertDatabaseHas('channel_messages', [
            'external_message_id' => 'wamid.BTN1',
            'body' => 'Yes, confirm',
        ]);
    }

    #[Test]
    public function check_whatsapp_api_probes_cloud_credentials(): void
    {
        config([
            'whatsapp.access_token' => 'wa-token',
            'whatsapp.phone_number_id' => '555',
            'whatsapp.graph_version' => 'v25.0',
            'app.url' => 'https://example.test',
        ]);

        Http::fake([
            'https://graph.facebook.com/v25.0/555*' => Http::response([
                'display_phone_number' => '+1 555-0100',
                'verified_name' => 'Sun Shop',
            ], 200),
        ]);

        RoleFind:
        Role::findOrCreate('admin');
        $user = User::factory()->create();
        $user->assignRole('admin');
        $this->actingAs($user);

        Livewire::test(AdminInbox::class)
            ->call('checkWhatsAppApi')
            ->assertSet('error', null)
            ->assertSee('WhatsApp Cloud API credentials are valid');
    }
}
