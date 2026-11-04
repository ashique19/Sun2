<?php

namespace Tests\Feature;

use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use App\Models\Setting;
use App\Services\Channels\ChannelConversationService;
use App\Services\Channels\InboxPricePhotoRequestService;
use App\Services\Channels\InboxQuickReplyMatcher;
use App\Services\Channels\InboxQuickReplyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InboxPricePhotoRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'channels.inbox.auto_photo_request' => true,
            'facebook.messenger.enabled' => true,
            'facebook.messenger.app_secret' => '',
            'facebook.messenger.page_access_token' => 'page-token',
            'facebook.graph_version' => 'v25.0',
            'gemini.api_key' => null,
        ]);
    }

    #[Test]
    public function price_query_without_photos_sends_which_product_photo_request(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'message_id' => 'm_auto_photo_1',
                'recipient_id' => 'psid-photo-req',
            ], 200),
        ]);

        $this->storeWhichProductReply();
        $conversation = $this->conversation('psid-photo-req');
        $inbound = $this->inbound($conversation, 'dam koto?');

        $sent = app(InboxPricePhotoRequestService::class)
            ->requestProductPhoto($conversation->fresh('messages'), $inbound);

        $this->assertNotNull($sent);
        $this->assertSame(ChannelMessage::DIRECTION_OUTBOUND, $sent->direction);
        $this->assertSame($inbound->id, $sent->reply_to_message_id);
        $this->assertSame('কোন প্রডাক্টের ছবিটা একটু পাঠাবেন?', $sent->body);
        $this->assertTrue($sent->raw_payload[InboxPricePhotoRequestService::RAW_FLAG] ?? false);
        $this->assertSame(1, $this->graphSendCount());
        $this->assertStringNotContainsString('১২০০', (string) $sent->body);
    }

    #[Test]
    public function uses_fallback_body_when_no_which_product_chip_is_saved(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'message_id' => 'm_auto_photo_fallback',
                'recipient_id' => 'psid-photo-fallback',
            ], 200),
        ]);

        app(InboxQuickReplyService::class)->save([
            ['label' => 'Price', 'body' => 'দাম ১২০০ টাকা'],
        ]);

        $conversation = $this->conversation('psid-photo-fallback');
        $inbound = $this->inbound($conversation, 'দাম কত');

        $sent = app(InboxPricePhotoRequestService::class)
            ->requestProductPhoto($conversation->fresh('messages'), $inbound);

        $this->assertNotNull($sent);
        $this->assertSame(InboxPricePhotoRequestService::FALLBACK_BODY, $sent->body);
        $this->assertStringNotContainsString('১২০০', (string) $sent->body);
    }

    #[Test]
    public function default_chip_is_a_which_product_photo_request(): void
    {
        $defaults = app(InboxQuickReplyService::class)->all();
        $which = collect($defaults)->first(
            fn (array $reply): bool => app(InboxQuickReplyMatcher::class)->isWhichProductReply($reply)
        );

        $this->assertNotNull($which);
        $this->assertSame('কোন প্রডাক্ট?', $which['label']);
        $this->assertSame(InboxPricePhotoRequestService::FALLBACK_BODY, $which['body']);
    }

    #[Test]
    public function greeting_does_not_send_a_photo_request(): void
    {
        Http::fake();
        $conversation = $this->conversation('psid-photo-hello');
        $inbound = $this->inbound($conversation, 'আসসালামু আলাইকুম');

        $this->assertFalse(
            app(InboxPricePhotoRequestService::class)
                ->shouldRequestProductPhoto($conversation->fresh('messages'), $inbound)
        );
        $this->assertNull(
            app(InboxPricePhotoRequestService::class)
                ->requestProductPhoto($conversation->fresh('messages'), $inbound)
        );
        Http::assertNothingSent();
    }

    #[Test]
    public function inbound_product_photo_skips_the_photo_request(): void
    {
        Http::fake();
        $conversation = $this->conversation('psid-photo-has-image');
        $this->inboundPhoto($conversation, now()->subMinute());
        $inbound = $this->inbound($conversation, 'এটা কত');

        $this->assertFalse(
            app(InboxPricePhotoRequestService::class)
                ->shouldRequestProductPhoto($conversation->fresh('messages'), $inbound)
        );
        $this->assertNull(
            app(InboxPricePhotoRequestService::class)
                ->requestProductPhoto($conversation->fresh('messages'), $inbound)
        );
        Http::assertNothingSent();
    }

    #[Test]
    public function stickers_do_not_block_the_photo_request(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'message_id' => 'm_auto_photo_sticker',
                'recipient_id' => 'psid-photo-sticker',
            ], 200),
        ]);

        $conversation = $this->conversation('psid-photo-sticker');
        ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'media_url' => 'https://example.test/sticker.png',
            'media_mime' => 'image/png',
            'raw_payload' => ['sticker_id' => 'sticker-1'],
            'sent_at' => now()->subMinute(),
        ]);
        $inbound = $this->inbound($conversation, 'dam koto?');

        $sent = app(InboxPricePhotoRequestService::class)
            ->requestProductPhoto($conversation->fresh('messages'), $inbound);

        $this->assertNotNull($sent);
        $this->assertTrue($sent->raw_payload[InboxPricePhotoRequestService::RAW_FLAG] ?? false);
    }

    #[Test]
    public function outside_messaging_window_does_not_send(): void
    {
        Http::fake();
        $conversation = $this->conversation('psid-photo-window');
        $inbound = $this->inbound($conversation, 'dam koto?', now()->subHours(25));
        $conversation->forceFill(['last_inbound_at' => now()->subHours(25)])->save();

        $this->assertFalse(
            app(InboxPricePhotoRequestService::class)
                ->shouldRequestProductPhoto($conversation->fresh('messages'), $inbound)
        );
        $this->assertNull(
            app(InboxPricePhotoRequestService::class)
                ->requestProductPhoto($conversation->fresh('messages'), $inbound)
        );
        Http::assertNothingSent();
    }

    #[Test]
    public function recent_auto_photo_request_is_not_repeated(): void
    {
        Http::fake();
        $conversation = $this->conversation('psid-photo-dedupe');
        $first = $this->inbound($conversation, 'dam koto?', now()->subHour());
        ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_OUTBOUND,
            'body' => InboxPricePhotoRequestService::FALLBACK_BODY,
            'reply_to_message_id' => $first->id,
            'raw_payload' => [InboxPricePhotoRequestService::RAW_FLAG => true],
            'sent_at' => now()->subMinutes(30),
        ]);
        $later = $this->inbound($conversation, 'দাম কত');

        $this->assertFalse(
            app(InboxPricePhotoRequestService::class)
                ->shouldRequestProductPhoto($conversation->fresh('messages'), $later)
        );
        $this->assertNull(
            app(InboxPricePhotoRequestService::class)
                ->requestProductPhoto($conversation->fresh('messages'), $later)
        );
        Http::assertNothingSent();
    }

    #[Test]
    public function staff_reply_after_the_price_ask_skips_auto_send(): void
    {
        Http::fake();
        $conversation = $this->conversation('psid-photo-staff');
        $inbound = $this->inbound($conversation, 'dam koto?', now()->subMinutes(2));
        ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_OUTBOUND,
            'body' => 'একটু অপেক্ষা করুন',
            'sent_at' => now()->subMinute(),
        ]);

        $this->assertFalse(
            app(InboxPricePhotoRequestService::class)
                ->shouldRequestProductPhoto($conversation->fresh('messages'), $inbound)
        );
        Http::assertNothingSent();
    }

    #[Test]
    public function disabled_config_does_not_send(): void
    {
        Http::fake();
        config(['channels.inbox.auto_photo_request' => false]);
        $conversation = $this->conversation('psid-photo-off');
        $inbound = $this->inbound($conversation, 'dam koto?');

        InboxPricePhotoRequestService::scheduleAfterInbound($conversation, $inbound);

        $this->assertFalse(
            app(InboxPricePhotoRequestService::class)
                ->shouldRequestProductPhoto($conversation->fresh('messages'), $inbound)
        );
        $this->assertNull(
            app(InboxPricePhotoRequestService::class)
                ->requestProductPhoto($conversation->fresh('messages'), $inbound)
        );
        Http::assertNothingSent();
    }

    #[Test]
    public function inbound_store_sends_the_photo_request_after_the_webhook(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'message_id' => 'm_auto_photo_webhook',
                'recipient_id' => 'PSID_PHOTO_WH',
            ], 200),
        ]);

        $body = json_encode([
            'object' => 'page',
            'entry' => [[
                'id' => 'PAGE123',
                'time' => time(),
                'messaging' => [[
                    'sender' => ['id' => 'PSID_PHOTO_WH'],
                    'recipient' => ['id' => 'PAGE123'],
                    'timestamp' => (int) (microtime(true) * 1000),
                    'message' => [
                        'mid' => 'm_price_ask',
                        'text' => 'dam koto?',
                    ],
                ]],
            ]],
        ], JSON_THROW_ON_ERROR);

        $this->call(
            'POST',
            '/api/webhooks/messenger',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            $body,
        )->assertOk();

        $conversation = ChannelConversation::query()
            ->where('external_user_id', 'PSID_PHOTO_WH')
            ->first();

        $this->assertNotNull($conversation);
        $outbound = ChannelMessage::query()
            ->where('channel_conversation_id', $conversation->id)
            ->where('direction', ChannelMessage::DIRECTION_OUTBOUND)
            ->get();

        $this->assertCount(1, $outbound);
        $this->assertSame(InboxPricePhotoRequestService::FALLBACK_BODY, $outbound->first()->body);
        $this->assertTrue($outbound->first()->raw_payload[InboxPricePhotoRequestService::RAW_FLAG] ?? false);
        $this->assertSame(1, $this->graphSendCount());
    }

    #[Test]
    public function inbound_store_does_not_send_for_non_price_text(): void
    {
        Http::fake();

        $conversation = $this->conversation('psid-photo-store-hi');
        app(ChannelConversationService::class)->storeMessage($conversation, [
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => 'hello',
            'sent_at' => now(),
        ]);

        $this->assertSame(
            0,
            ChannelMessage::query()
                ->where('channel_conversation_id', $conversation->id)
                ->where('direction', ChannelMessage::DIRECTION_OUTBOUND)
                ->count()
        );
        Http::assertNothingSent();
    }

    /**
     * @return list<array{id: string, label: string, body: string}>
     */
    private function storeWhichProductReply(): array
    {
        $replies = app(InboxQuickReplyService::class)->save([
            ['label' => 'কোন প্রডাক্ট?', 'body' => 'কোন প্রডাক্টের ছবিটা একটু পাঠাবেন?'],
            ['label' => 'Price', 'body' => 'দাম ১২০০ টাকা'],
        ]);
        Setting::putValue(
            InboxQuickReplyService::SETTING_KEY,
            json_encode($replies, JSON_UNESCAPED_UNICODE),
            'channels',
        );

        return $replies;
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

    private function inboundPhoto(ChannelConversation $conversation, mixed $sentAt = null): ChannelMessage
    {
        return ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'media_url' => 'https://example.test/product-'.uniqid().'.jpg',
            'media_mime' => 'image/jpeg',
            'sent_at' => $sentAt ?? now(),
        ]);
    }

    private function graphSendCount(): int
    {
        return collect(Http::recorded())
            ->filter(function (array $pair): bool {
                $url = (string) $pair[0]->url();

                return str_contains($url, '/me/messages')
                    && is_array($pair[0]->data())
                    && isset($pair[0]->data()['message']['text']);
            })
            ->count();
    }
}
