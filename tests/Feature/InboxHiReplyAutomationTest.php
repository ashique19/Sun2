<?php

namespace Tests\Feature;

use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use App\Services\Channels\InboxAutomationSettings;
use App\Services\Channels\InboxHiReplyAutomationService;
use App\Services\Channels\InboxQuickReplyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InboxHiReplyAutomationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'channels.inbox.auto_hi_reply' => true,
            'facebook.messenger.enabled' => true,
            'facebook.messenger.app_secret' => '',
            'facebook.messenger.page_access_token' => 'page-token',
            'facebook.graph_version' => 'v25.0',
        ]);

        app(InboxQuickReplyService::class)->save([
            ['label' => 'Hi', 'body' => 'Hello there'],
            ['label' => 'Salaam', 'body' => 'আসসালামু আলাইকুম'],
        ]);
    }

    #[Test]
    public function inbound_hi_sends_hi_apu(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'message_id' => 'm_auto_hi',
                'recipient_id' => 'psid-hi',
            ], 200),
        ]);

        $conversation = $this->conversation('psid-hi');
        $inbound = $this->inbound($conversation, 'Hi');

        $sent = app(InboxHiReplyAutomationService::class)
            ->reply($conversation, $inbound);

        $this->assertNotNull($sent);
        $this->assertSame(InboxHiReplyAutomationService::BODY, $sent->body);
        $this->assertTrue($sent->raw_payload[InboxHiReplyAutomationService::RAW_FLAG] ?? false);
        $this->assertSame($inbound->id, $sent->reply_to_message_id);
        $this->assertSame(1, Http::recorded()->count());
    }

    #[Test]
    public function tagged_hi_chip_sends_even_when_the_text_is_not_hi(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'message_id' => 'm_auto_hi_tag',
                'recipient_id' => 'psid-hi-tag',
            ], 200),
        ]);

        $hi = collect(app(InboxQuickReplyService::class)->all())
            ->first(fn (array $reply): bool => $reply['label'] === 'Hi');
        $this->assertNotNull($hi);

        $conversation = $this->conversation('psid-hi-tag');
        $inbound = $this->inbound($conversation, 'assalamualaikum apu');
        $inbound->forceFill(['quick_reply_id' => $hi['id']])->save();

        $sent = app(InboxHiReplyAutomationService::class)
            ->reply($conversation, $inbound->fresh());

        $this->assertNotNull($sent);
        $this->assertSame(InboxHiReplyAutomationService::BODY, $sent->body);
    }

    #[Test]
    public function repeated_hi_in_the_session_sends_once(): void
    {
        Http::fake(function () {
            return Http::response([
                'message_id' => 'm_auto_hi_once_'.uniqid('', true),
                'recipient_id' => 'psid-hi-once',
            ], 200);
        });

        $conversation = $this->conversation('psid-hi-once');
        $first = $this->inbound($conversation, 'hi', now()->subSeconds(6));
        $service = app(InboxHiReplyAutomationService::class);
        $this->assertNotNull($service->reply($conversation, $first));

        $second = $this->inbound($conversation, 'hello');
        $this->assertNull($service->reply($conversation, $second));

        $this->assertSame(1, ChannelMessage::query()
            ->where('channel_conversation_id', $conversation->id)
            ->where('direction', ChannelMessage::DIRECTION_OUTBOUND)
            ->count());
        $this->assertSame(1, Http::recorded()->count());
    }

    #[Test]
    public function salaam_does_not_send_hi_apu(): void
    {
        Http::fake();
        $conversation = $this->conversation('psid-hi-salaam');
        $inbound = $this->inbound($conversation, 'আসসালামু আলাইকুম');

        $this->assertNull(
            app(InboxHiReplyAutomationService::class)->reply($conversation, $inbound)
        );
        Http::assertNothingSent();
    }

    #[Test]
    public function no_hi_chip_does_not_send(): void
    {
        Http::fake();
        app(InboxQuickReplyService::class)->save([
            ['label' => 'Salaam', 'body' => 'আসসালামু আলাইকুম'],
        ]);

        $conversation = $this->conversation('psid-hi-no-chip');
        $inbound = $this->inbound($conversation, 'Hi');

        $this->assertNull(
            app(InboxHiReplyAutomationService::class)->reply($conversation, $inbound)
        );
        Http::assertNothingSent();
    }

    #[Test]
    public function disabled_setting_does_not_send(): void
    {
        Http::fake();
        app(InboxAutomationSettings::class)->save([
            InboxAutomationSettings::HI_REPLY => false,
        ]);

        $conversation = $this->conversation('psid-hi-off');
        $inbound = $this->inbound($conversation, 'Hi');

        $this->assertNull(
            app(InboxHiReplyAutomationService::class)->reply($conversation, $inbound)
        );
        Http::assertNothingSent();
    }

    private function conversation(string $psid): ChannelConversation
    {
        return ChannelConversation::query()->create([
            'channel' => ChannelConversation::CHANNEL_MESSENGER,
            'external_user_id' => $psid,
            'customer_name' => 'Karim',
            'last_inbound_at' => now(),
        ]);
    }

    private function inbound(ChannelConversation $conversation, string $body, mixed $sentAt = null): ChannelMessage
    {
        return ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => $body,
            'sent_at' => $sentAt ?? now(),
        ]);
    }
}
