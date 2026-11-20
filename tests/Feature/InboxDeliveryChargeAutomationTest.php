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
use Illuminate\Support\Carbon;
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

        Carbon::setTestNow(Carbon::parse('2026-11-19 12:00:00', 'Asia/Dhaka'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
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
    #[DataProvider('timeQuestionsWithoutArea')]
    public function time_questions_without_an_area_send_the_general_reply(string $text): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'message_id' => 'm_auto_delivery_time_'.md5($text),
                'recipient_id' => 'psid-delivery-time',
            ], 200),
        ]);

        $conversation = $this->conversation('psid-delivery-time-'.md5($text));
        $inbound = $this->inbound($conversation, $text);

        $sent = app(InboxDeliveryChargeAutomationService::class)
            ->reply($conversation, $inbound);

        $this->assertNotNull($sent, $text);
        $this->assertSame(InboxDeliveryChargeAutomationService::BODY, $sent->body, $text);
        $this->assertStringNotContainsString('Kotodin', (string) $sent->body);
        $this->assertStringNotContainsString('kotodin', mb_strtolower((string) $sent->body));
        $this->assertStringNotContainsString('কতদিন', (string) $sent->body);
    }

    /**
     * @return list<list<string>>
     */
    public static function timeQuestionsWithoutArea(): array
    {
        return [
            ['Kotodin lagbe delivery hoben?'],
            ['koto din lagbe delivery?'],
            ['কতদিন লাগবে ডেলিভারি?'],
            ['delivery kotodin lagbe'],
        ];
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
            InboxDeliveryChargeAutomationService::personalizedBody($area),
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
            ['তবলছড়ি, ঢাকা ডেলিভারি কত', 'তবলছড়ি'],
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
                InboxDeliveryChargeAutomationService::personalizedDhakaBody('Mirpur'),
            ],
            [
                'uttara delivery charge',
                InboxDeliveryChargeAutomationService::personalizedDhakaBody('uttara'),
            ],
            ['ঢাকা শহর এ ডেলিভারি চার্জ কত', InboxDeliveryChargeAutomationService::BODY_DHAKA],
            [
                'dhanmondi te koto din lagbe delivery',
                InboxDeliveryChargeAutomationService::personalizedDhakaBody('dhanmondi'),
            ],
            [
                'মিরপুর ডেলিভারি কত',
                InboxDeliveryChargeAutomationService::personalizedDhakaBody('মিরপুর'),
            ],
            [
                'মোহাম্মদপুর ডেলিভারি কত',
                InboxDeliveryChargeAutomationService::personalizedDhakaBody('মোহাম্মদপুর'),
            ],
            [
                'মিরপুর, ঢাকা ডেলিভারি কত',
                InboxDeliveryChargeAutomationService::personalizedDhakaBody('মিরপুর'),
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
            InboxDeliveryChargeAutomationService::personalizedBody('Savar'),
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
            InboxDeliveryChargeAutomationService::personalizedBody('Ashulia'),
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
            InboxDeliveryChargeAutomationService::personalizedBody('Keraniganj'),
            $sent->body,
        );
    }

    #[Test]
    public function catalog_thana_under_dhaka_is_inside_even_when_not_a_hardcoded_cue(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'message_id' => 'm_auto_delivery_catalog_thana',
                'recipient_id' => 'psid-delivery-catalog-thana',
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
            'name' => 'Khilgaon',
            'slug' => 'khilgaon',
            'unit_type' => 'thana',
            'is_active' => true,
            'delivery_charge_upto_5' => 80,
            'delivery_charge_over_5' => 150,
        ]);

        $conversation = $this->conversation('psid-delivery-catalog-thana');
        $inbound = $this->inbound($conversation, 'Khilgaon, Dhaka delivery charge koto?');

        $sent = app(InboxDeliveryChargeAutomationService::class)
            ->reply($conversation, $inbound);

        $this->assertNotNull($sent);
        $this->assertSame(
            InboxDeliveryChargeAutomationService::personalizedDhakaBody('Khilgaon'),
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
    public function kobe_paben_dispatch_questions_do_not_send(): void
    {
        Http::fake();
        $conversation = $this->conversation('psid-delivery-paben');
        $inbound = $this->inbound($conversation, 'কবে পাবেন');

        $this->assertNull(
            app(InboxDeliveryChargeAutomationService::class)->reply($conversation, $inbound)
        );
        Http::assertNothingSent();
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
    public function kobe_pabo_without_area_asks_for_the_delivery_area(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'message_id' => 'm_auto_kobe_ask',
                'recipient_id' => 'psid-delivery-kobe',
            ], 200),
        ]);

        $conversation = $this->conversation('psid-delivery-kobe');
        $inbound = $this->inbound($conversation, 'কবে পাব');

        $sent = app(InboxDeliveryChargeAutomationService::class)
            ->reply($conversation, $inbound);

        $this->assertNotNull($sent);
        $this->assertSame(InboxDeliveryChargeAutomationService::BODY_KOBE_ASK, $sent->body);
        $this->assertTrue($sent->raw_payload[InboxDeliveryChargeAutomationService::RAW_FLAG] ?? false);
        $this->assertSame(
            InboxDeliveryChargeAutomationService::KIND_KOBE_ASK,
            $sent->raw_payload[InboxDeliveryChargeAutomationService::RAW_KIND] ?? null,
        );
        $this->assertSame($inbound->id, $sent->reply_to_message_id);
    }

    #[Test]
    #[DataProvider('kobePaboPhrases')]
    public function kobe_pabo_phrasings_ask_for_the_delivery_area(string $text): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'message_id' => 'm_auto_kobe_ask_'.md5($text),
                'recipient_id' => 'psid-delivery-kobe-phrase',
            ], 200),
        ]);

        $conversation = $this->conversation('psid-delivery-kobe-phrase-'.md5($text));
        $inbound = $this->inbound($conversation, $text);

        $sent = app(InboxDeliveryChargeAutomationService::class)
            ->reply($conversation, $inbound);

        $this->assertNotNull($sent, $text);
        $this->assertSame(InboxDeliveryChargeAutomationService::BODY_KOBE_ASK, $sent->body, $text);
    }

    /**
     * @return list<list<string>>
     */
    public static function kobePaboPhrases(): array
    {
        return [
            ['কবে পাবো'],
            ['kobe pabo?'],
            ['আমারটা কবে পাব'],
        ];
    }

    #[Test]
    public function kobe_pabo_with_mirpur_names_dhaka_weekdays(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'message_id' => 'm_auto_kobe_dhaka',
                'recipient_id' => 'psid-delivery-kobe-dhaka',
            ], 200),
        ]);

        $conversation = $this->conversation('psid-delivery-kobe-dhaka');
        $inbound = $this->inbound($conversation, 'মিরপুর কবে পাব');

        $sent = app(InboxDeliveryChargeAutomationService::class)
            ->reply($conversation, $inbound);

        $this->assertNotNull($sent);
        $this->assertSame(
            InboxDeliveryChargeAutomationService::weekdayDeliveryBody(InboxDeliveryChargeAutomationService::ZONE_DHAKA),
            $sent->body,
        );
        $this->assertSame('আজকে অর্ডার করলে রবিবার অথবা সোমবার ডেলিভারি পাবেন', $sent->body);
        $this->assertSame(
            InboxDeliveryChargeAutomationService::KIND_KOBE_DAYS,
            $sent->raw_payload[InboxDeliveryChargeAutomationService::RAW_KIND] ?? null,
        );
    }

    #[Test]
    public function kobe_pabo_with_savar_names_outside_weekdays(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'message_id' => 'm_auto_kobe_out',
                'recipient_id' => 'psid-delivery-kobe-out',
            ], 200),
        ]);

        $conversation = $this->conversation('psid-delivery-kobe-out');
        $inbound = $this->inbound($conversation, 'সাভার কবে পাব');

        $sent = app(InboxDeliveryChargeAutomationService::class)
            ->reply($conversation, $inbound);

        $this->assertNotNull($sent);
        $this->assertSame(
            InboxDeliveryChargeAutomationService::weekdayDeliveryBody(InboxDeliveryChargeAutomationService::ZONE_OUTSIDE),
            $sent->body,
        );
        $this->assertSame('আজকে অর্ডার করলে সোমবার অথবা মংগলবার ডেলিভারি পাবেন', $sent->body);
    }

    #[Test]
    public function kobe_pabo_uses_a_session_area_and_skips_the_ask(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'message_id' => 'm_auto_kobe_session',
                'recipient_id' => 'psid-delivery-kobe-session',
            ], 200),
        ]);

        $conversation = $this->conversation('psid-delivery-kobe-session');
        $this->inbound($conversation, 'মিরপুর', now()->subMinutes(4));
        $inbound = $this->inbound($conversation, 'কবে পাব');

        $sent = app(InboxDeliveryChargeAutomationService::class)
            ->reply($conversation, $inbound);

        $this->assertNotNull($sent);
        $this->assertSame('আজকে অর্ডার করলে রবিবার অথবা সোমবার ডেলিভারি পাবেন', $sent->body);
    }

    #[Test]
    public function kobe_ask_then_mirpur_names_dhaka_weekdays(): void
    {
        Http::fake(function () {
            return Http::response([
                'message_id' => 'm_auto_kobe_follow_'.uniqid('', true),
                'recipient_id' => 'psid-delivery-kobe-follow',
            ], 200);
        });

        $conversation = $this->conversation('psid-delivery-kobe-follow');
        $service = app(InboxDeliveryChargeAutomationService::class);

        $ask = $this->inbound($conversation, 'কবে পাবো', now()->subSeconds(20));
        $asked = $service->reply($conversation, $ask);
        $this->assertNotNull($asked);
        $this->assertSame(InboxDeliveryChargeAutomationService::BODY_KOBE_ASK, $asked->body);

        $area = $this->inbound($conversation, 'মিরপুর');
        $sent = $service->reply($conversation, $area);

        $this->assertNotNull($sent);
        $this->assertSame('আজকে অর্ডার করলে রবিবার অথবা সোমবার ডেলিভারি পাবেন', $sent->body);
        $this->assertSame(2, Http::recorded()->count());
    }

    #[Test]
    public function kobe_ask_then_savar_names_outside_weekdays(): void
    {
        Http::fake(function () {
            return Http::response([
                'message_id' => 'm_auto_kobe_follow_out_'.uniqid('', true),
                'recipient_id' => 'psid-delivery-kobe-follow-out',
            ], 200);
        });

        $conversation = $this->conversation('psid-delivery-kobe-follow-out');
        $service = app(InboxDeliveryChargeAutomationService::class);

        $this->assertNotNull($service->reply(
            $conversation,
            $this->inbound($conversation, 'কবে পাব', now()->subSeconds(12)),
        ));

        $sent = $service->reply($conversation, $this->inbound($conversation, 'সাভার'));

        $this->assertNotNull($sent);
        $this->assertSame('আজকে অর্ডার করলে সোমবার অথবা মংগলবার ডেলিভারি পাবেন', $sent->body);
    }

    #[Test]
    public function repeated_kobe_asks_without_area_send_once(): void
    {
        Http::fake(function () {
            return Http::response([
                'message_id' => 'm_auto_kobe_once_'.uniqid('', true),
                'recipient_id' => 'psid-delivery-kobe-once',
            ], 200);
        });

        $conversation = $this->conversation('psid-delivery-kobe-once');
        $service = app(InboxDeliveryChargeAutomationService::class);

        $this->assertNotNull($service->reply(
            $conversation,
            $this->inbound($conversation, 'কবে পাব', now()->subSeconds(10)),
        ));
        $this->assertNull($service->reply($conversation, $this->inbound($conversation, 'কবে পাবো')));
        $this->assertSame(1, Http::recorded()->count());
    }

    #[Test]
    public function kobe_ask_then_unrelated_text_does_not_send_weekdays(): void
    {
        Http::fake(function () {
            return Http::response([
                'message_id' => 'm_auto_kobe_noise_'.uniqid('', true),
                'recipient_id' => 'psid-delivery-kobe-noise',
            ], 200);
        });

        $conversation = $this->conversation('psid-delivery-kobe-noise');
        $service = app(InboxDeliveryChargeAutomationService::class);
        $this->assertNotNull($service->reply(
            $conversation,
            $this->inbound($conversation, 'কবে পাব', now()->subSeconds(8)),
        ));

        $this->assertNull($service->reply($conversation, $this->inbound($conversation, 'ok')));
        $this->assertSame(1, Http::recorded()->count());
    }

    #[Test]
    public function charge_ask_then_kobe_pabo_still_asks_for_area(): void
    {
        Http::fake(function () {
            return Http::response([
                'message_id' => 'm_auto_charge_then_kobe_'.uniqid('', true),
                'recipient_id' => 'psid-delivery-charge-then-kobe',
            ], 200);
        });

        $conversation = $this->conversation('psid-delivery-charge-then-kobe');
        $service = app(InboxDeliveryChargeAutomationService::class);

        $charge = $service->reply(
            $conversation,
            $this->inbound($conversation, 'ডেলিভারি চার্জ কত', now()->subSeconds(15)),
        );
        $this->assertNotNull($charge);
        $this->assertSame(InboxDeliveryChargeAutomationService::BODY, $charge->body);

        $ask = $service->reply($conversation, $this->inbound($conversation, 'কবে পাব'));
        $this->assertNotNull($ask);
        $this->assertSame(InboxDeliveryChargeAutomationService::BODY_KOBE_ASK, $ask->body);
        $this->assertSame(2, Http::recorded()->count());
    }

    #[Test]
    public function weekday_offsets_match_dhaka_plus_three_four_and_outside_plus_four_five(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-15 09:00:00', 'Asia/Dhaka'));

        $this->assertSame(
            'আজকে অর্ডার করলে বুধবার অথবা বৃহস্পতিবার ডেলিভারি পাবেন',
            InboxDeliveryChargeAutomationService::weekdayDeliveryBody(InboxDeliveryChargeAutomationService::ZONE_DHAKA),
        );
        $this->assertSame(
            'আজকে অর্ডার করলে বৃহস্পতিবার অথবা শুক্রবার ডেলিভারি পাবেন',
            InboxDeliveryChargeAutomationService::weekdayDeliveryBody(InboxDeliveryChargeAutomationService::ZONE_OUTSIDE),
        );
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
    public function a_price_ask_does_not_send_delivery_time(): void
    {
        Http::fake();
        app(InboxQuickReplyService::class)->save([
            [
                'label' => InboxDeliveryChargeAutomationService::REPLY_LABEL,
                'body' => InboxDeliveryChargeAutomationService::BODY,
            ],
            ['label' => 'কোন প্রডাক্ট?', 'body' => 'কোন প্রডাক্টটা দেখতে চান?'],
        ]);

        $conversation = $this->conversation('psid-delivery-price-ask');
        $inbound = $this->inbound($conversation, 'price');

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
