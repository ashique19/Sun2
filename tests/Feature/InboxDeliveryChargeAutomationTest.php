<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use App\Models\City;
use App\Services\Channels\InboxAutomationSettings;
use App\Services\Channels\InboxDeliveryChargeAutomationService;
use App\Services\Channels\InboxQuickReplyMatcher;
use App\Services\Channels\InboxQuickReplyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InboxDeliveryChargeAutomationTest extends TestCase
{
    use RefreshDatabase;

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
    }

    #[Test]
    public function inbound_delivery_charge_ask_sends_the_general_reply(): void
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
        $this->assertSame(InboxDeliveryChargeAutomationService::BODY, $sent->body);
        $this->assertTrue($sent->raw_payload[InboxDeliveryChargeAutomationService::RAW_FLAG] ?? false);
        $this->assertSame($inbound->id, $sent->reply_to_message_id);
        $this->assertSame(1, Http::recorded()->count());
    }

    #[Test]
    #[DataProvider('outsideAreas')]
    public function named_outside_area_mentions_that_area_in_the_reply(string $text, string $area): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'message_id' => 'm_auto_delivery_out_'.md5($text),
                'recipient_id' => 'psid-delivery-out',
            ], 200),
        ]);

        $conversation = $this->conversation('psid-delivery-out-'.md5($text));
        $inbound = $this->inbound($conversation, $text);

        $sent = app(InboxDeliveryChargeAutomationService::class)
            ->reply($conversation, $inbound);

        $this->assertNotNull($sent, $text);
        $this->assertSame(
            InboxDeliveryChargeAutomationService::personalizedBody(
                $area,
                InboxDeliveryChargeAutomationService::ZONE_OUTSIDE,
            ),
            $sent->body,
        );
    }

    /**
     * @return list<list<string>>
     */
    public static function outsideAreas(): array
    {
        return [
            ['Savar e delivery koto?', 'Savar'],
            ['ashulia delivery charge?', 'ashulia'],
            ['Gazipur e ডেলিভারি চার্জ কত', 'Gazipur'],
            ['tongi te koto din lagbe delivery', 'tongi'],
            ['Narayanganj delivery time?', 'Narayanganj'],
            ['গাজীপুর এ ডেলিভারি চার্জ', 'গাজীপুর'],
            ['সাভার ডেলিভারি কত', 'সাভার'],
            ['তবলছড়ি ডেলিভারি কত', 'তবলছড়ি'],
        ];
    }

    #[Test]
    #[DataProvider('dhakaCityAreas')]
    public function named_dhaka_city_area_mentions_that_area_in_the_reply(string $text, string $expected): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'message_id' => 'm_auto_delivery_dhaka_'.md5($text),
                'recipient_id' => 'psid-delivery-dhaka',
            ], 200),
        ]);

        $conversation = $this->conversation('psid-delivery-dhaka-'.md5($text));
        $inbound = $this->inbound($conversation, $text);

        $sent = app(InboxDeliveryChargeAutomationService::class)
            ->reply($conversation, $inbound);

        $this->assertNotNull($sent, $text);
        $this->assertSame($expected, $sent->body);
    }

    /**
     * @return list<list<string>>
     */
    public static function dhakaCityAreas(): array
    {
        return [
            [
                'Mirpur e delivery koto?',
                InboxDeliveryChargeAutomationService::personalizedBody(
                    'Mirpur',
                    InboxDeliveryChargeAutomationService::ZONE_DHAKA,
                ),
            ],
            [
                'uttara delivery charge',
                InboxDeliveryChargeAutomationService::personalizedBody(
                    'uttara',
                    InboxDeliveryChargeAutomationService::ZONE_DHAKA,
                ),
            ],
            ['ঢাকা শহর এ ডেলিভারি চার্জ কত', InboxDeliveryChargeAutomationService::BODY_DHAKA],
            [
                'dhanmondi te koto din lagbe delivery',
                InboxDeliveryChargeAutomationService::personalizedBody(
                    'dhanmondi',
                    InboxDeliveryChargeAutomationService::ZONE_DHAKA,
                ),
            ],
        ];
    }

    #[Test]
    public function savar_wins_over_dhaka_in_the_same_message(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'message_id' => 'm_auto_delivery_savar_dhaka',
                'recipient_id' => 'psid-delivery-savar-dhaka',
            ], 200),
        ]);

        $conversation = $this->conversation('psid-delivery-savar-dhaka');
        $inbound = $this->inbound($conversation, 'Savar, Dhaka delivery charge?');

        $sent = app(InboxDeliveryChargeAutomationService::class)
            ->reply($conversation, $inbound);

        $this->assertNotNull($sent);
        $this->assertSame(
            InboxDeliveryChargeAutomationService::personalizedBody(
                'Savar',
                InboxDeliveryChargeAutomationService::ZONE_OUTSIDE,
            ),
            $sent->body,
        );
    }

    #[Test]
    public function area_named_earlier_in_the_session_selects_the_matching_line(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'message_id' => 'm_auto_delivery_session_area',
                'recipient_id' => 'psid-delivery-session',
            ], 200),
        ]);

        $conversation = $this->conversation('psid-delivery-session');
        $this->inbound($conversation, 'Ashulia, Dhaka', now()->subMinutes(2));
        $inbound = $this->inbound($conversation, 'ডেলিভারি চার্জ কত');

        $sent = app(InboxDeliveryChargeAutomationService::class)
            ->reply($conversation, $inbound);

        $this->assertNotNull($sent);
        $this->assertSame(
            InboxDeliveryChargeAutomationService::personalizedBody(
                'Ashulia',
                InboxDeliveryChargeAutomationService::ZONE_OUTSIDE,
            ),
            $sent->body,
        );
    }

    #[Test]
    public function catalog_upazila_under_dhaka_is_outside(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'message_id' => 'm_auto_delivery_catalog',
                'recipient_id' => 'psid-delivery-catalog',
            ], 200),
        ]);

        $city = City::query()->create([
            'name' => 'Dhaka',
            'slug' => 'dhaka-dhaka',
            'is_dhaka' => true,
            'is_active' => true,
        ]);
        Area::query()->create([
            'city_id' => $city->id,
            'name' => 'Keraniganj',
            'slug' => 'keraniganj',
            'unit_type' => 'upazila',
            'is_active' => true,
            'delivery_charge_upto_5' => 120,
            'delivery_charge_over_5' => 200,
        ]);

        $conversation = $this->conversation('psid-delivery-catalog');
        $inbound = $this->inbound($conversation, 'Keraniganj delivery charge koto?');

        $sent = app(InboxDeliveryChargeAutomationService::class)
            ->reply($conversation, $inbound);

        $this->assertNotNull($sent);
        $this->assertSame(
            InboxDeliveryChargeAutomationService::personalizedBody(
                'Keraniganj',
                InboxDeliveryChargeAutomationService::ZONE_OUTSIDE,
            ),
            $sent->body,
        );
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

        app(InboxQuickReplyService::class)->save([
            ['label' => InboxDeliveryChargeAutomationService::REPLY_LABEL, 'body' => 'ignored chip body'],
            ['label' => 'Salaam', 'body' => 'আসসালামু আলাইকুম'],
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
        $this->assertSame(InboxDeliveryChargeAutomationService::BODY, $sent->body);
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
