<?php

namespace Tests\Feature;

use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use App\Services\Channels\InboxAdvanceAutomationService;
use App\Services\Channels\InboxAutomationSettings;
use App\Services\Channels\InboxDeliveryChargeAutomationService;
use App\Services\Channels\InboxQuickReplyMatcher;
use App\Services\Channels\InboxQuickReplyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InboxAdvanceAutomationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'channels.inbox.auto_advance' => true,
            'channels.inbox.auto_delivery_charge' => true,
            'facebook.messenger.enabled' => true,
            'facebook.messenger.app_secret' => '',
            'facebook.messenger.page_access_token' => 'page-token',
            'facebook.graph_version' => 'v25.0',
        ]);
    }

    #[Test]
    #[DataProvider('listedAdvanceAsks')]
    public function listed_area_asks_for_prepaid_delivery_charge(string $text, string $area): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'message_id' => 'm_auto_advance_'.md5($text),
                'recipient_id' => 'psid-advance',
            ], 200),
        ]);

        $conversation = $this->conversation('psid-advance-'.md5($text));
        $inbound = $this->inbound($conversation, $text);

        $sent = app(InboxAdvanceAutomationService::class)
            ->reply($conversation, $inbound);

        $this->assertNotNull($sent, $text);
        $this->assertSame(InboxAdvanceAutomationService::listedBody($area), $sent->body);
        $this->assertTrue($sent->raw_payload[InboxAdvanceAutomationService::RAW_FLAG] ?? false);
        $this->assertSame(InboxAdvanceAutomationService::KIND_LISTED, $sent->raw_payload[InboxAdvanceAutomationService::RAW_KIND] ?? null);
        $this->assertSame($inbound->id, $sent->reply_to_message_id);
    }

    /**
     * @return list<list<string>>
     */
    public static function listedAdvanceAsks(): array
    {
        return [
            ['কুমিল্লা এডভান্স লাগবে?', 'কুমিল্লা'],
            ['teknaf e advance lagbe?', 'teknaf'],
            ['উখিয়া তে আগে ডেলিভারি চার্জ দিতে হবে?', 'উখিয়া'],
            ['লামা তে এডভান্স কত', 'লামা'],
        ];
    }

    #[Test]
    public function advance_without_area_asks_the_location(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'message_id' => 'm_auto_advance_ask',
                'recipient_id' => 'psid-advance-ask',
            ], 200),
        ]);

        $conversation = $this->conversation('psid-advance-ask');
        $inbound = $this->inbound($conversation, 'এডভান্স লাগবে?');

        $sent = app(InboxAdvanceAutomationService::class)
            ->reply($conversation, $inbound);

        $this->assertNotNull($sent);
        $this->assertSame(InboxAdvanceAutomationService::BODY_ASK_LOCATION, $sent->body);
    }

    #[Test]
    public function location_follow_up_after_the_ask_uses_the_listed_area_line(): void
    {
        Http::fake(function () {
            return Http::response([
                'message_id' => 'm_auto_advance_follow_'.uniqid('', true),
                'recipient_id' => 'psid-advance-follow',
            ], 200);
        });

        $conversation = $this->conversation('psid-advance-follow');
        $first = $this->inbound($conversation, 'এডভান্স লাগবে?', now()->subSeconds(20));
        $service = app(InboxAdvanceAutomationService::class);
        $this->assertSame(
            InboxAdvanceAutomationService::BODY_ASK_LOCATION,
            $service->reply($conversation, $first)?->body
        );

        $second = $this->inbound($conversation, 'কুমিল্লা');
        $sent = $service->reply($conversation, $second);

        $this->assertNotNull($sent);
        $this->assertSame(InboxAdvanceAutomationService::listedBody('কুমিল্লা'), $sent->body);
    }

    #[Test]
    public function unlisted_area_gets_the_prepaid_area_list(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'message_id' => 'm_auto_advance_unlisted',
                'recipient_id' => 'psid-advance-unlisted',
            ], 200),
        ]);

        $conversation = $this->conversation('psid-advance-unlisted');
        $inbound = $this->inbound($conversation, 'মিরপুর এ এডভান্স লাগবে?');

        $sent = app(InboxAdvanceAutomationService::class)
            ->reply($conversation, $inbound);

        $this->assertNotNull($sent);
        $this->assertSame(InboxAdvanceAutomationService::unlistedBody(), $sent->body);
        $this->assertStringContainsString('কুমিল্লা', $sent->body);
        $this->assertStringContainsString('এছাড়া অন্য কোথাও এডভান্স লাগবে না।', $sent->body);
    }

    #[Test]
    public function pressing_for_full_cod_after_a_listed_area_sends_the_refusal(): void
    {
        Http::fake(function () {
            return Http::response([
                'message_id' => 'm_auto_advance_cod_'.uniqid('', true),
                'recipient_id' => 'psid-advance-cod',
            ], 200);
        });

        $conversation = $this->conversation('psid-advance-cod');
        $service = app(InboxAdvanceAutomationService::class);
        $first = $this->inbound($conversation, 'কুমিল্লা এডভান্স লাগবে?', now()->subSeconds(15));
        $this->assertSame(
            InboxAdvanceAutomationService::listedBody('কুমিল্লা'),
            $service->reply($conversation, $first)?->body
        );

        $second = $this->inbound($conversation, 'পুরো ক্যাশ অন ডেলিভারি চাই');
        $sent = $service->reply($conversation, $second);

        $this->assertNotNull($sent);
        $this->assertSame(InboxAdvanceAutomationService::codRefusalBody('কুমিল্লা'), $sent->body);
    }

    #[Test]
    public function regular_delivery_charge_ask_still_goes_to_delivery_automation(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'message_id' => 'm_auto_advance_delivery',
                'recipient_id' => 'psid-advance-delivery',
            ], 200),
        ]);

        $conversation = $this->conversation('psid-advance-delivery');
        $inbound = $this->inbound($conversation, 'কুমিল্লা ডেলিভারি কত');

        $this->assertNull(app(InboxAdvanceAutomationService::class)->reply($conversation, $inbound));
        $sent = app(InboxDeliveryChargeAutomationService::class)->reply($conversation, $inbound);
        $this->assertNotNull($sent);
        $this->assertSame(InboxDeliveryChargeAutomationService::personalizedBody('কুমিল্লা'), $sent->body);
    }

    #[Test]
    public function tagged_advance_chip_sends_even_when_the_text_is_not_an_advance_ask(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'message_id' => 'm_auto_advance_tag',
                'recipient_id' => 'psid-advance-tag',
            ], 200),
        ]);

        app(InboxQuickReplyService::class)->save([
            ['label' => InboxAdvanceAutomationService::REPLY_LABEL, 'body' => InboxAdvanceAutomationService::BODY_ASK_LOCATION],
        ]);
        $chip = collect(app(InboxQuickReplyService::class)->all())
            ->first(fn (array $reply): bool => app(InboxQuickReplyMatcher::class)->isAdvanceReply($reply));
        $this->assertNotNull($chip);

        $conversation = $this->conversation('psid-advance-tag');
        $inbound = $this->inbound($conversation, 'apu ekta kotha');
        $inbound->forceFill(['quick_reply_id' => $chip['id']])->save();

        $sent = app(InboxAdvanceAutomationService::class)
            ->reply($conversation, $inbound->fresh());

        $this->assertNotNull($sent);
        $this->assertSame(InboxAdvanceAutomationService::BODY_ASK_LOCATION, $sent->body);
    }

    #[Test]
    public function repeated_listed_asks_in_the_session_send_once(): void
    {
        Http::fake(function () {
            return Http::response([
                'message_id' => 'm_auto_advance_once_'.uniqid('', true),
                'recipient_id' => 'psid-advance-once',
            ], 200);
        });

        $conversation = $this->conversation('psid-advance-once');
        $service = app(InboxAdvanceAutomationService::class);
        $first = $this->inbound($conversation, 'কুমিল্লা এডভান্স লাগবে?', now()->subSeconds(8));
        $this->assertNotNull($service->reply($conversation, $first));

        $second = $this->inbound($conversation, 'টেকনাফ এডভান্স লাগবে?');
        $this->assertNull($service->reply($conversation, $second));
        $this->assertSame(1, Http::recorded()->count());
    }

    #[Test]
    public function disabled_setting_does_not_send(): void
    {
        Http::fake();
        app(InboxAutomationSettings::class)->save([
            InboxAutomationSettings::ADVANCE => false,
        ]);

        $conversation = $this->conversation('psid-advance-off');
        $inbound = $this->inbound($conversation, 'কুমিল্লা এডভান্স লাগবে?');

        $this->assertNull(app(InboxAdvanceAutomationService::class)->reply($conversation, $inbound));
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
