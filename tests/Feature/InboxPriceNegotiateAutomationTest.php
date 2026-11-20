<?php

namespace Tests\Feature;

use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use App\Services\Channels\InboxAutomationSettings;
use App\Services\Channels\InboxPriceNegotiateAutomationService;
use App\Services\Channels\InboxQuickReplyMatcher;
use App\Services\Channels\InboxQuickReplyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InboxPriceNegotiateAutomationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'channels.inbox.auto_price_negotiate' => true,
            'facebook.messenger.enabled' => true,
            'facebook.messenger.app_secret' => '',
            'facebook.messenger.page_access_token' => 'page-token',
            'facebook.graph_version' => 'v25.0',
        ]);
    }

    #[Test]
    public function first_negotiate_in_the_session_sends_the_fixed_price_reply(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'message_id' => 'm_auto_nego_1',
                'recipient_id' => 'psid-nego',
            ], 200),
        ]);

        $conversation = $this->conversation('psid-nego');
        $inbound = $this->inbound($conversation, 'discount hobe?');

        $sent = app(InboxPriceNegotiateAutomationService::class)
            ->reply($conversation, $inbound);

        $this->assertNotNull($sent);
        $this->assertSame(InboxPriceNegotiateAutomationService::BODY_1, $sent->body);
        $this->assertTrue($sent->raw_payload[InboxPriceNegotiateAutomationService::RAW_FLAG] ?? false);
        $this->assertSame($inbound->id, $sent->reply_to_message_id);
        $this->assertSame(1, Http::recorded()->count());
    }

    #[Test]
    public function second_push_sends_the_no_discount_reply(): void
    {
        Http::fake(function () {
            return Http::response([
                'message_id' => 'm_auto_nego_2_'.uniqid('', true),
                'recipient_id' => 'psid-nego-2',
            ], 200);
        });

        $conversation = $this->conversation('psid-nego-2');
        $service = app(InboxPriceNegotiateAutomationService::class);

        $first = $this->inbound($conversation, 'dam komu', now()->subSeconds(8));
        $this->assertSame(
            InboxPriceNegotiateAutomationService::BODY_1,
            $service->reply($conversation, $first)?->body,
        );

        $second = $this->inbound($conversation, 'কমাবেন আপু');
        $this->assertSame(
            InboxPriceNegotiateAutomationService::BODY_2,
            $service->reply($conversation, $second)?->body,
        );
        $this->assertSame(2, Http::recorded()->count());
    }

    #[Test]
    public function further_pushes_keep_repeating_sorry_apu(): void
    {
        Http::fake(function () {
            return Http::response([
                'message_id' => 'm_auto_nego_3_'.uniqid('', true),
                'recipient_id' => 'psid-nego-3',
            ], 200);
        });

        $conversation = $this->conversation('psid-nego-3');
        $service = app(InboxPriceNegotiateAutomationService::class);

        $this->assertNotNull($service->reply(
            $conversation,
            $this->inbound($conversation, 'discount?', now()->subSeconds(12)),
        ));
        $this->assertNotNull($service->reply(
            $conversation,
            $this->inbound($conversation, 'last price den', now()->subSeconds(6)),
        ));

        $third = $this->inbound($conversation, 'একটু কম দিবেন', now()->subSeconds(3));
        $this->assertSame(
            InboxPriceNegotiateAutomationService::BODY_3,
            $service->reply($conversation, $third)?->body,
        );

        $fourth = $this->inbound($conversation, 'ডিস্কাউন্ট দেন');
        $this->assertSame(
            InboxPriceNegotiateAutomationService::BODY_3,
            $service->reply($conversation, $fourth)?->body,
        );
        $this->assertSame(4, Http::recorded()->count());
    }

    #[Test]
    public function price_asks_do_not_send_the_negotiate_replies(): void
    {
        Http::fake();
        $conversation = $this->conversation('psid-nego-price');
        $service = app(InboxPriceNegotiateAutomationService::class);

        $this->assertNull($service->reply($conversation, $this->inbound($conversation, 'দাম কত')));
        $this->assertNull($service->reply($conversation, $this->inbound($conversation, 'dam koto?')));
        Http::assertNothingSent();
    }

    #[Test]
    public function same_inbound_does_not_send_twice(): void
    {
        Http::fake(function () {
            return Http::response([
                'message_id' => 'm_auto_nego_once_'.uniqid('', true),
                'recipient_id' => 'psid-nego-once',
            ], 200);
        });

        $conversation = $this->conversation('psid-nego-once');
        $inbound = $this->inbound($conversation, 'discount hobe?');
        $service = app(InboxPriceNegotiateAutomationService::class);

        $this->assertNotNull($service->reply($conversation, $inbound));
        $this->assertNull($service->reply($conversation, $inbound));
        $this->assertSame(1, Http::recorded()->count());
    }

    #[Test]
    public function session_reset_starts_at_the_first_reply_again(): void
    {
        Http::fake(function () {
            return Http::response([
                'message_id' => 'm_auto_nego_hour_'.uniqid('', true),
                'recipient_id' => 'psid-nego-hour',
            ], 200);
        });

        $conversation = $this->conversation('psid-nego-hour');
        $service = app(InboxPriceNegotiateAutomationService::class);

        $first = $this->inbound($conversation, 'discount?', now()->subMinutes(70));
        $this->assertSame(
            InboxPriceNegotiateAutomationService::BODY_1,
            $service->reply($conversation, $first)?->body,
        );

        ChannelMessage::query()
            ->where('channel_conversation_id', $conversation->id)
            ->where('direction', ChannelMessage::DIRECTION_OUTBOUND)
            ->update(['sent_at' => now()->subMinutes(70)]);

        $later = $this->inbound($conversation, 'কমাবেন');
        $this->assertSame(
            InboxPriceNegotiateAutomationService::BODY_1,
            $service->reply($conversation, $later)?->body,
        );
    }

    #[Test]
    public function disabled_setting_does_not_send(): void
    {
        Http::fake();
        app(InboxAutomationSettings::class)->save([
            InboxAutomationSettings::PRICE_NEGOTIATE => false,
        ]);

        $conversation = $this->conversation('psid-nego-off');
        $inbound = $this->inbound($conversation, 'discount hobe?');

        $this->assertNull(
            app(InboxPriceNegotiateAutomationService::class)->reply($conversation, $inbound)
        );
        Http::assertNothingSent();
    }

    #[Test]
    public function tagged_fixed_price_chip_sends_even_when_the_text_is_not_a_haggle(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'message_id' => 'm_auto_nego_tag',
                'recipient_id' => 'psid-nego-tag',
            ], 200),
        ]);

        app(InboxQuickReplyService::class)->save([
            ['label' => 'দাম ফিক্সড', 'body' => InboxPriceNegotiateAutomationService::BODY_1],
        ]);
        $chip = collect(app(InboxQuickReplyService::class)->all())
            ->first(fn (array $reply): bool => $reply['label'] === 'দাম ফিক্সড');
        $this->assertNotNull($chip);

        $conversation = $this->conversation('psid-nego-tag');
        $inbound = $this->inbound($conversation, 'apu please');
        $inbound->forceFill(['quick_reply_id' => $chip['id']])->save();

        $sent = app(InboxPriceNegotiateAutomationService::class)
            ->reply($conversation, $inbound->fresh());

        $this->assertNotNull($sent);
        $this->assertSame(InboxPriceNegotiateAutomationService::BODY_1, $sent->body);
    }

    #[Test]
    public function english_price_fixed_chip_sends_when_the_saved_reply_is_matched(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'message_id' => 'm_auto_nego_price_fixed',
                'recipient_id' => 'psid-nego-price-fixed',
            ], 200),
        ]);

        $replies = app(InboxQuickReplyService::class)->save([
            ['label' => 'price fixed', 'body' => 'Sorry apu, the price is fixed.'],
        ]);
        $chip = collect($replies)->first(fn (array $reply): bool => $reply['label'] === 'price fixed');
        $this->assertNotNull($chip);

        app(InboxQuickReplyMatcher::class)->addExample($chip['id'], 'possible kina apu');

        $conversation = $this->conversation('psid-nego-price-fixed');
        $inbound = $this->inbound($conversation, 'possible kina apu');

        $sent = app(InboxPriceNegotiateAutomationService::class)
            ->reply($conversation, $inbound);

        $this->assertNotNull($sent);
        $this->assertSame(InboxPriceNegotiateAutomationService::BODY_1, $sent->body);
    }

    #[Test]
    public function tagged_english_price_fixed_chip_sends_even_when_the_text_is_not_a_haggle(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'message_id' => 'm_auto_nego_en_tag',
                'recipient_id' => 'psid-nego-en-tag',
            ], 200),
        ]);

        $replies = app(InboxQuickReplyService::class)->save([
            ['label' => 'Price Fixed', 'body' => 'The price is fixed apu.'],
        ]);
        $chip = collect($replies)->first(fn (array $reply): bool => $reply['label'] === 'Price Fixed');
        $this->assertNotNull($chip);

        $conversation = $this->conversation('psid-nego-en-tag');
        $inbound = $this->inbound($conversation, 'apu please');
        $inbound->forceFill(['quick_reply_id' => $chip['id']])->save();

        $sent = app(InboxPriceNegotiateAutomationService::class)
            ->reply($conversation, $inbound->fresh());

        $this->assertNotNull($sent);
        $this->assertSame(InboxPriceNegotiateAutomationService::BODY_1, $sent->body);
    }

    #[Test]
    public function customer_price_fixed_wording_sends_without_a_tag(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'message_id' => 'm_auto_nego_en_text',
                'recipient_id' => 'psid-nego-en-text',
            ], 200),
        ]);

        $conversation = $this->conversation('psid-nego-en-text');
        $inbound = $this->inbound($conversation, 'price fixed?');

        $sent = app(InboxPriceNegotiateAutomationService::class)
            ->reply($conversation, $inbound);

        $this->assertNotNull($sent);
        $this->assertSame(InboxPriceNegotiateAutomationService::BODY_1, $sent->body);
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
