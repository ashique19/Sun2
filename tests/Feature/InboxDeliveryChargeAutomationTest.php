<?php

namespace Tests\Feature;

use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use App\Services\Channels\InboxAutomationSettings;
use App\Services\Channels\InboxDeliveryChargeAutomationService;
use App\Services\Channels\InboxQuickReplyMatcher;
use App\Services\Channels\InboxQuickReplyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InboxDeliveryChargeAutomationTest extends TestCase
{
    use RefreshDatabase;

    private const CHIP_BODY = 'ডেলিভারি চার্জ ১২০ টাকা, ৩-৫ দিনের মাঝে পাবেন।';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'channels.inbox.auto_delivery_charge' => true,
            'facebook.messenger.enabled' => true,
            'facebook.messenger.app_secret' => '',
            'facebook.messenger.page_access_token' => 'page-token',
            'facebook.graph_version' => 'v25.0',
        ]);

        app(InboxQuickReplyService::class)->save([
            ['label' => InboxDeliveryChargeAutomationService::REPLY_LABEL, 'body' => self::CHIP_BODY],
            ['label' => 'Salaam', 'body' => 'আসসালামু আলাইকুম'],
        ]);
    }

    #[Test]
    public function inbound_delivery_charge_ask_sends_the_saved_reply(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'message_id' => 'm_auto_delivery',
                'recipient_id' => 'psid-delivery',
            ], 200),
        ]);

        $conversation = $this->conversation('psid-delivery');
        $inbound = $this->inbound($conversation, 'ডেলিভারি চার্জ ও সময়');

        $sent = app(InboxDeliveryChargeAutomationService::class)
            ->reply($conversation, $inbound);

        $this->assertNotNull($sent);
        $this->assertSame(self::CHIP_BODY, $sent->body);
        $this->assertTrue($sent->raw_payload[InboxDeliveryChargeAutomationService::RAW_FLAG] ?? false);
        $this->assertSame($inbound->id, $sent->reply_to_message_id);
        $this->assertSame(1, Http::recorded()->count());
    }

    #[Test]
    public function edited_chip_body_is_what_gets_sent(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'message_id' => 'm_auto_delivery_edit',
                'recipient_id' => 'psid-delivery-edit',
            ], 200),
        ]);

        app(InboxQuickReplyService::class)->save([
            ['label' => InboxDeliveryChargeAutomationService::REPLY_LABEL, 'body' => 'ডেলিভারি চার্জ ১৫০ টাকা, ৪-৬ দিন।'],
        ]);

        $conversation = $this->conversation('psid-delivery-edit');
        $inbound = $this->inbound($conversation, 'Delivery koto');

        $sent = app(InboxDeliveryChargeAutomationService::class)
            ->reply($conversation, $inbound);

        $this->assertNotNull($sent);
        $this->assertSame('ডেলিভারি চার্জ ১৫০ টাকা, ৪-৬ দিন।', $sent->body);
    }

    #[Test]
    public function tagged_chip_sends_even_when_the_text_is_not_a_charge_ask(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'message_id' => 'm_auto_delivery_tag',
                'recipient_id' => 'psid-delivery-tag',
            ], 200),
        ]);

        $chip = collect(app(InboxQuickReplyService::class)->all())
            ->first(fn (array $reply): bool => app(InboxQuickReplyMatcher::class)->isDeliveryChargeReply($reply));
        $this->assertNotNull($chip);

        $conversation = $this->conversation('psid-delivery-tag');
        $inbound = $this->inbound($conversation, 'কবে পাঠাবেন');
        $inbound->forceFill(['quick_reply_id' => $chip['id']])->save();

        $sent = app(InboxDeliveryChargeAutomationService::class)
            ->reply($conversation, $inbound->fresh());

        $this->assertNotNull($sent);
        $this->assertSame(self::CHIP_BODY, $sent->body);
    }

    #[Test]
    public function parcel_when_questions_do_not_send(): void
    {
        Http::fake();
        $conversation = $this->conversation('psid-delivery-parcel');
        $inbound = $this->inbound($conversation, 'আপু আমার পার্সেল টা কবে পাঠাবেন???');

        $this->assertNull(
            app(InboxDeliveryChargeAutomationService::class)->reply($conversation, $inbound)
        );
        Http::assertNothingSent();
    }

    #[Test]
    public function kobe_pabo_without_charge_does_not_send(): void
    {
        Http::fake();
        $conversation = $this->conversation('psid-delivery-kobe');
        $inbound = $this->inbound($conversation, 'কবে পাবো');

        $this->assertNull(
            app(InboxDeliveryChargeAutomationService::class)->reply($conversation, $inbound)
        );
        Http::assertNothingSent();
    }

    #[Test]
    public function repeated_asks_in_the_session_send_once(): void
    {
        Http::fake(function () {
            return Http::response([
                'message_id' => 'm_auto_delivery_once_'.uniqid('', true),
                'recipient_id' => 'psid-delivery-once',
            ], 200);
        });

        $conversation = $this->conversation('psid-delivery-once');
        $first = $this->inbound($conversation, 'ডেলিভারি চার্জ কত', now()->subSeconds(8));
        $service = app(InboxDeliveryChargeAutomationService::class);
        $this->assertNotNull($service->reply($conversation, $first));

        $second = $this->inbound($conversation, 'Delivery Time & Charge ?');
        $this->assertNull($service->reply($conversation, $second));

        $this->assertSame(1, ChannelMessage::query()
            ->where('channel_conversation_id', $conversation->id)
            ->where('direction', ChannelMessage::DIRECTION_OUTBOUND)
            ->count());
        $this->assertSame(1, Http::recorded()->count());
    }

    #[Test]
    public function no_chip_does_not_send(): void
    {
        Http::fake();
        app(InboxQuickReplyService::class)->save([
            ['label' => 'Salaam', 'body' => 'আসসালামু আলাইকুম'],
        ]);

        $conversation = $this->conversation('psid-delivery-no-chip');
        $inbound = $this->inbound($conversation, 'ডেলিভারি চার্জ কত');

        $this->assertNull(
            app(InboxDeliveryChargeAutomationService::class)->reply($conversation, $inbound)
        );
        Http::assertNothingSent();
    }

    #[Test]
    public function disabled_setting_does_not_send(): void
    {
        Http::fake();
        app(InboxAutomationSettings::class)->save([
            InboxAutomationSettings::DELIVERY_CHARGE => false,
        ]);

        $conversation = $this->conversation('psid-delivery-off');
        $inbound = $this->inbound($conversation, 'ডেলিভারি চার্জ কত');

        $this->assertNull(
            app(InboxDeliveryChargeAutomationService::class)->reply($conversation, $inbound)
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
