<?php

namespace Tests\Feature;

use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use App\Services\Channels\InboxAdvanceAutomationService;
use App\Services\Channels\InboxAutomationSettings;
use App\Services\Channels\InboxDeliveryChargeAutomationService;
use App\Services\Channels\InboxOrderProcessAutomationService;
use App\Services\Channels\InboxQuickReplyMatcher;
use App\Services\Channels\InboxQuickReplyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InboxOrderProcessAutomationTest extends TestCase
{
    use RefreshDatabase;

    private const CHIP_BODY = 'অর্ডার করতে নাম, সম্পূর্ণ ঠিকানা আর মোবাইল নাম্বার দিয়ে মেসেজ করুন।';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'channels.inbox.auto_order_process' => true,
            'channels.inbox.auto_delivery_charge' => true,
            'channels.inbox.auto_advance' => true,
            'facebook.messenger.enabled' => true,
            'facebook.messenger.app_secret' => '',
            'facebook.messenger.page_access_token' => 'page-token',
            'facebook.graph_version' => 'v25.0',
        ]);

        app(InboxQuickReplyService::class)->save([
            ['label' => InboxOrderProcessAutomationService::REPLY_LABEL, 'body' => self::CHIP_BODY],
            ['label' => 'Thanks', 'body' => 'ধন্যবাদ, অর্ডার কনফার্ম করা হয়েছে।'],
        ]);
    }

    #[Test]
    #[DataProvider('orderAsks')]
    public function inbound_order_ask_sends_the_saved_reply(string $text): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'message_id' => 'm_auto_order_'.md5($text),
                'recipient_id' => 'psid-order',
            ], 200),
        ]);

        $conversation = $this->conversation('psid-order-'.md5($text));
        $inbound = $this->inbound($conversation, $text);

        $sent = app(InboxOrderProcessAutomationService::class)
            ->reply($conversation, $inbound);

        $this->assertNotNull($sent, $text);
        $this->assertSame(self::CHIP_BODY, $sent->body);
        $this->assertTrue($sent->raw_payload[InboxOrderProcessAutomationService::RAW_FLAG] ?? false);
        $this->assertSame($inbound->id, $sent->reply_to_message_id);
        $this->assertSame(1, Http::recorded()->count());
    }

    /**
     * @return list<list<string>>
     */
    public static function orderAsks(): array
    {
        return [
            ['kivabe order'],
            ['কিভাবে অর্ডার করব'],
            ['how to order'],
            ['order korte chai'],
            ['অর্ডার করতে চাই'],
            ['place order'],
        ];
    }

    #[Test]
    #[DataProvider('nonOrderAsks')]
    public function other_messages_do_not_send_the_order_process_reply(string $text): void
    {
        Http::fake();
        $conversation = $this->conversation('psid-order-skip-'.md5($text));
        $inbound = $this->inbound($conversation, $text);

        $this->assertNull(
            app(InboxOrderProcessAutomationService::class)->reply($conversation, $inbound),
            $text,
        );
        Http::assertNothingSent();
    }

    /**
     * @return list<list<string>>
     */
    public static function nonOrderAsks(): array
    {
        return [
            ['দাম কত'],
            ['dam koto?'],
            ['Hi'],
            ['মিরপুর ডেলিভারি কত'],
            ["নাম:রূনলে ম্রো\nঠিকানা:বান্দরবান।\nমোবাইল :01810992298"],
        ];
    }

    #[Test]
    public function tagged_order_process_chip_sends_even_when_the_text_is_not_an_order_ask(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'message_id' => 'm_auto_order_tag',
                'recipient_id' => 'psid-order-tag',
            ], 200),
        ]);

        $chip = collect(app(InboxQuickReplyService::class)->all())
            ->first(fn (array $reply): bool => app(InboxQuickReplyMatcher::class)->isOrderProcessReply($reply));
        $this->assertNotNull($chip);

        $conversation = $this->conversation('psid-order-tag');
        $inbound = $this->inbound($conversation, 'apu ekta kotha');
        $inbound->forceFill(['quick_reply_id' => $chip['id']])->save();

        $sent = app(InboxOrderProcessAutomationService::class)
            ->reply($conversation, $inbound->fresh());

        $this->assertNotNull($sent);
        $this->assertSame(self::CHIP_BODY, $sent->body);
    }

    #[Test]
    public function order_ask_with_kobe_pabo_also_sends_the_delivery_time_reply(): void
    {
        Http::fake(function () {
            return Http::response([
                'message_id' => 'm_auto_order_kobe_'.uniqid('', true),
                'recipient_id' => 'psid-order-kobe',
            ], 200);
        });

        $conversation = $this->conversation('psid-order-kobe');
        $inbound = $this->inbound($conversation, 'kivabe order kobe pabo');

        $sent = app(InboxOrderProcessAutomationService::class)
            ->reply($conversation, $inbound);

        $this->assertNotNull($sent);
        $this->assertSame(
            [self::CHIP_BODY, InboxDeliveryChargeAutomationService::BODY_KOBE_ASK],
            $this->outboundBodies($conversation),
        );
    }

    #[Test]
    public function order_ask_with_advance_also_sends_the_advance_reply(): void
    {
        Http::fake(function () {
            return Http::response([
                'message_id' => 'm_auto_order_adv_'.uniqid('', true),
                'recipient_id' => 'psid-order-adv',
            ], 200);
        });

        $conversation = $this->conversation('psid-order-adv');
        $inbound = $this->inbound($conversation, 'অর্ডার করতে চাই এডভান্স লাগবে?');

        $sent = app(InboxOrderProcessAutomationService::class)
            ->reply($conversation, $inbound);

        $this->assertNotNull($sent);
        $this->assertSame(
            [self::CHIP_BODY, InboxAdvanceAutomationService::BODY_ASK_LOCATION],
            $this->outboundBodies($conversation),
        );
    }

    #[Test]
    public function order_ask_with_kobe_and_advance_sends_the_wait_fallback(): void
    {
        Http::fake(function () {
            return Http::response([
                'message_id' => 'm_auto_order_combo_'.uniqid('', true),
                'recipient_id' => 'psid-order-combo',
            ], 200);
        });

        $conversation = $this->conversation('psid-order-combo');
        $inbound = $this->inbound($conversation, 'অর্ডার করতে চাই কবে পাব এডভান্স লাগবে?');

        $sent = app(InboxOrderProcessAutomationService::class)
            ->reply($conversation, $inbound);

        $this->assertNotNull($sent);
        $this->assertSame(
            [self::CHIP_BODY, InboxOrderProcessAutomationService::BODY_FALLBACK],
            $this->outboundBodies($conversation),
        );
    }

    #[Test]
    public function named_listed_area_order_sends_advance_not_the_regular_charge(): void
    {
        Http::fake(function () {
            return Http::response([
                'message_id' => 'm_auto_order_area_'.uniqid('', true),
                'recipient_id' => 'psid-order-area',
            ], 200);
        });

        $conversation = $this->conversation('psid-order-area');
        $inbound = $this->inbound($conversation, 'কুমিল্লা অর্ডার করতে চাই');

        $sent = app(InboxOrderProcessAutomationService::class)
            ->reply($conversation, $inbound);

        $this->assertNotNull($sent);
        $this->assertSame(
            [
                self::CHIP_BODY,
                InboxAdvanceAutomationService::listedBody('কুমিল্লা'),
            ],
            $this->outboundBodies($conversation),
        );
    }

    #[Test]
    public function listed_area_order_with_kobe_pabo_sends_weekdays_and_advance(): void
    {
        Http::fake(function () {
            return Http::response([
                'message_id' => 'm_auto_order_kobe_area_'.uniqid('', true),
                'recipient_id' => 'psid-order-kobe-area',
            ], 200);
        });

        Carbon::setTestNow(Carbon::parse('2026-11-19 12:00:00', 'Asia/Dhaka'));

        $conversation = $this->conversation('psid-order-kobe-area');
        $inbound = $this->inbound($conversation, 'কুমিল্লা অর্ডার করতে চাই কবে পাব');

        $sent = app(InboxOrderProcessAutomationService::class)
            ->reply($conversation, $inbound);

        $this->assertNotNull($sent);
        $this->assertSame(
            [
                self::CHIP_BODY,
                InboxDeliveryChargeAutomationService::weekdayDeliveryBody(InboxDeliveryChargeAutomationService::ZONE_OUTSIDE),
                InboxAdvanceAutomationService::listedBody('কুমিল্লা'),
            ],
            $this->outboundBodies($conversation),
        );

        Carbon::setTestNow();
    }

    #[Test]
    public function matched_order_process_example_sends_the_saved_reply(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'message_id' => 'm_auto_order_ex',
                'recipient_id' => 'psid-order-ex',
            ], 200),
        ]);

        $chip = collect(app(InboxQuickReplyService::class)->all())
            ->first(fn (array $reply): bool => $reply['label'] === InboxOrderProcessAutomationService::REPLY_LABEL);
        $this->assertNotNull($chip);
        app(InboxQuickReplyMatcher::class)->addExample($chip['id'], 'ami nite chai apu');

        $conversation = $this->conversation('psid-order-ex');
        $inbound = $this->inbound($conversation, 'ami nite chai apu');

        $sent = app(InboxOrderProcessAutomationService::class)
            ->reply($conversation, $inbound);

        $this->assertNotNull($sent);
        $this->assertSame(self::CHIP_BODY, $sent->body);
    }

    #[Test]
    public function thanks_chip_does_not_send_the_order_process_reply(): void
    {
        Http::fake();
        $chip = collect(app(InboxQuickReplyService::class)->all())
            ->first(fn (array $reply): bool => $reply['label'] === 'Thanks');
        $this->assertNotNull($chip);

        $conversation = $this->conversation('psid-order-thanks');
        $inbound = $this->inbound($conversation, 'ok apu');
        $inbound->forceFill(['quick_reply_id' => $chip['id']])->save();

        $this->assertNull(
            app(InboxOrderProcessAutomationService::class)->reply($conversation, $inbound->fresh())
        );
        Http::assertNothingSent();
    }

    #[Test]
    public function missing_saved_reply_does_not_send(): void
    {
        Http::fake();
        app(InboxQuickReplyService::class)->save([
            ['label' => 'Salaam', 'body' => 'আসসালামু আলাইকুম'],
        ]);

        $conversation = $this->conversation('psid-order-none');
        $inbound = $this->inbound($conversation, 'kivabe order');

        $this->assertNull(
            app(InboxOrderProcessAutomationService::class)->reply($conversation, $inbound)
        );
        Http::assertNothingSent();
    }

    #[Test]
    public function repeated_asks_in_the_session_send_once(): void
    {
        Http::fake(function () {
            return Http::response([
                'message_id' => 'm_auto_order_once_'.uniqid('', true),
                'recipient_id' => 'psid-order-once',
            ], 200);
        });

        $conversation = $this->conversation('psid-order-once');
        $service = app(InboxOrderProcessAutomationService::class);
        $first = $this->inbound($conversation, 'kivabe order', now()->subSeconds(8));
        $this->assertNotNull($service->reply($conversation, $first));

        $second = $this->inbound($conversation, 'how to order');
        $this->assertNull($service->reply($conversation, $second));

        $this->assertSame(1, ChannelMessage::query()
            ->where('channel_conversation_id', $conversation->id)
            ->where('direction', ChannelMessage::DIRECTION_OUTBOUND)
            ->count());
        $this->assertSame(1, Http::recorded()->count());
    }

    #[Test]
    public function disabled_setting_does_not_send(): void
    {
        Http::fake();
        app(InboxAutomationSettings::class)->save([
            InboxAutomationSettings::ORDER_PROCESS => false,
        ]);

        $conversation = $this->conversation('psid-order-off');
        $inbound = $this->inbound($conversation, 'kivabe order');

        $this->assertNull(
            app(InboxOrderProcessAutomationService::class)->reply($conversation, $inbound)
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

    /**
     * @return list<string>
     */
    private function outboundBodies(ChannelConversation $conversation): array
    {
        return ChannelMessage::query()
            ->where('channel_conversation_id', $conversation->id)
            ->where('direction', ChannelMessage::DIRECTION_OUTBOUND)
            ->orderBy('id')
            ->pluck('body')
            ->all();
    }
}
