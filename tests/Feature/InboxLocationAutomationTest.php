<?php

namespace Tests\Feature;

use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use App\Services\Channels\InboxAutomationSettings;
use App\Services\Channels\InboxLocationAutomationService;
use App\Services\Channels\InboxQuickReplyMatcher;
use App\Services\Channels\InboxQuickReplyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InboxLocationAutomationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'channels.inbox.auto_location' => true,
            'facebook.messenger.enabled' => true,
            'facebook.messenger.app_secret' => '',
            'facebook.messenger.page_access_token' => 'page-token',
            'facebook.graph_version' => 'v25.0',
        ]);
    }

    #[Test]
    #[DataProvider('locationAsks')]
    public function inbound_location_ask_sends_the_shop_address(string $text): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'message_id' => 'm_auto_location_'.md5($text),
                'recipient_id' => 'psid-location',
            ], 200),
        ]);

        $conversation = $this->conversation('psid-location-'.md5($text));
        $inbound = $this->inbound($conversation, $text);

        $sent = app(InboxLocationAutomationService::class)
            ->reply($conversation, $inbound);

        $this->assertNotNull($sent, $text);
        $this->assertSame(InboxLocationAutomationService::BODY, $sent->body);
        $this->assertTrue($sent->raw_payload[InboxLocationAutomationService::RAW_FLAG] ?? false);
        $this->assertSame($inbound->id, $sent->reply_to_message_id);
        $this->assertSame(1, Http::recorded()->count());
    }

    /**
     * @return list<list<string>>
     */
    public static function locationAsks(): array
    {
        return [
            ['শোরুম আছে?'],
            ['showroom kothay?'],
            ['আপনাদের ঠিকানা কি'],
            ['location?'],
            ['লোকেশন?'],
            ['অফিস কোথায়'],
            ['warehouse kothay'],
            ['আপনাদের অফিসের ঠিকানা'],
        ];
    }

    #[Test]
    #[DataProvider('nonLocationAsks')]
    public function other_messages_do_not_send_the_shop_address(string $text): void
    {
        Http::fake();
        $conversation = $this->conversation('psid-location-skip-'.md5($text));
        $inbound = $this->inbound($conversation, $text);

        $this->assertNull(
            app(InboxLocationAutomationService::class)->reply($conversation, $inbound),
            $text,
        );
        Http::assertNothingSent();
    }

    /**
     * @return list<list<string>>
     */
    public static function nonLocationAsks(): array
    {
        return [
            ['মিরপুর ডেলিভারি কত'],
            ['আমার ঠিকানা মিরপুর'],
            ['দাম কত'],
            ['Hi'],
            ["নাম:রূনলে ম্রো\nঠিকানা:বান্দরবান।\nমোবাইল :01810992298"],
            ['ঠিকানা দিলাম'],
        ];
    }

    #[Test]
    public function tagged_location_chip_sends_even_when_the_text_is_not_a_location_ask(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'message_id' => 'm_auto_location_tag',
                'recipient_id' => 'psid-location-tag',
            ], 200),
        ]);

        app(InboxQuickReplyService::class)->save([
            ['label' => InboxLocationAutomationService::REPLY_LABEL, 'body' => InboxLocationAutomationService::BODY],
            ['label' => 'Salaam', 'body' => 'আসসালামু আলাইকুম'],
        ]);
        $chip = collect(app(InboxQuickReplyService::class)->all())
            ->first(fn (array $reply): bool => app(InboxQuickReplyMatcher::class)->isLocationReply($reply));
        $this->assertNotNull($chip);

        $conversation = $this->conversation('psid-location-tag');
        $inbound = $this->inbound($conversation, 'apu ekta kotha');
        $inbound->forceFill(['quick_reply_id' => $chip['id']])->save();

        $sent = app(InboxLocationAutomationService::class)
            ->reply($conversation, $inbound->fresh());

        $this->assertNotNull($sent);
        $this->assertSame(InboxLocationAutomationService::BODY, $sent->body);
    }

    #[Test]
    public function address_chip_does_not_send_the_shop_location(): void
    {
        Http::fake();
        app(InboxQuickReplyService::class)->save([
            ['label' => 'Address?', 'body' => 'আপনার সম্পূর্ণ ঠিকানাটা একটু দিবেন?'],
        ]);
        $chip = collect(app(InboxQuickReplyService::class)->all())
            ->first(fn (array $reply): bool => $reply['label'] === 'Address?');
        $this->assertNotNull($chip);

        $conversation = $this->conversation('psid-location-address-chip');
        $inbound = $this->inbound($conversation, 'মিরপুর ১০');
        $inbound->forceFill(['quick_reply_id' => $chip['id']])->save();

        $this->assertNull(
            app(InboxLocationAutomationService::class)->reply($conversation, $inbound->fresh())
        );
        Http::assertNothingSent();
    }

    #[Test]
    public function repeated_asks_in_the_session_send_once(): void
    {
        Http::fake(function () {
            return Http::response([
                'message_id' => 'm_auto_location_once_'.uniqid('', true),
                'recipient_id' => 'psid-location-once',
            ], 200);
        });

        $conversation = $this->conversation('psid-location-once');
        $first = $this->inbound($conversation, 'শোরুম আছে?', now()->subSeconds(8));
        $service = app(InboxLocationAutomationService::class);
        $this->assertNotNull($service->reply($conversation, $first));

        $second = $this->inbound($conversation, 'location kothay?');
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
            InboxAutomationSettings::LOCATION => false,
        ]);

        $conversation = $this->conversation('psid-location-off');
        $inbound = $this->inbound($conversation, 'শোরুম আছে?');

        $this->assertNull(
            app(InboxLocationAutomationService::class)->reply($conversation, $inbound)
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
