<?php

namespace Tests\Unit;

use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use App\Models\InboxQuickReplyExample;
use App\Models\Product;
use App\Services\Channels\ChannelMessageImageMatchService;
use App\Services\Channels\InboxQuickReplyMatcher;
use App\Services\Channels\InboxQuickReplyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InboxQuickReplyMatcherTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    #[DataProvider('priceQueries')]
    public function detects_price_queries_in_bangla_english_and_typos(string $text): void
    {
        $this->assertSame(
            InboxQuickReplyMatcher::INTENT_PRICE,
            $this->matcher()->detectIntent($text),
        );
    }

    /**
     * @return list<list<string>>
     */
    public static function priceQueries(): array
    {
        return [
            ['dam koto?'],
            ['দাম কত'],
            ['eta koto'],
            ['eita koto'],
            ['prize pls'],
            ['price?'],
            ['এটা কত'],
            ['koto tk'],
        ];
    }

    #[Test]
    #[DataProvider('deliveryQueries')]
    public function detects_delivery_time_queries(string $text): void
    {
        $this->assertSame(
            InboxQuickReplyMatcher::INTENT_DELIVERY,
            $this->matcher()->detectIntent($text),
        );
    }

    /**
     * @return list<list<string>>
     */
    public static function deliveryQueries(): array
    {
        return [
            ['kobe pabo?'],
            ['কবে পাবো'],
            ['delivary koto din'],
            ['কতদিন লাগবে'],
            ['delivery time'],
        ];
    }

    #[Test]
    #[DataProvider('orderQueries')]
    public function detects_order_process_queries(string $text): void
    {
        $this->assertSame(
            InboxQuickReplyMatcher::INTENT_ORDER,
            $this->matcher()->detectIntent($text),
        );
    }

    /**
     * @return list<list<string>>
     */
    public static function orderQueries(): array
    {
        return [
            ['kivabe order'],
            ['কিভাবে অর্ডার করব'],
            ['how to order'],
            ['ki vabe nibo'],
        ];
    }

    #[Test]
    public function koto_din_prefers_delivery_over_price(): void
    {
        $this->assertSame(
            InboxQuickReplyMatcher::INTENT_DELIVERY,
            $this->matcher()->detectIntent('koto din lagbe?'),
        );
    }

    #[Test]
    public function greeting_is_not_an_intent(): void
    {
        $this->assertNull($this->matcher()->detectIntent('hello'));
        $this->assertNull($this->matcher()->detectIntent('আসসালামু আলাইকুম'));
    }

    #[Test]
    public function maps_price_intent_to_a_price_quick_reply(): void
    {
        $replies = $this->replies();
        $conversation = $this->conversation();
        $message = $this->inbound($conversation, 'dam koto?');

        $matches = $this->matcher()->matchThread($conversation->fresh()->load('messages'), $replies);

        $this->assertSame($replies[0]['id'], $matches[$message->id]['id']);
        $this->assertSame(InboxQuickReplyMatcher::VIA_INTENT, $matches[$message->id]['via']);
        $this->assertFalse($matches[$message->id]['tagged']);
    }

    #[Test]
    public function tagged_example_matches_a_typo_variant(): void
    {
        $replies = $this->replies();
        $conversation = $this->conversation();
        $tagged = $this->inbound($conversation, 'shippy when');
        $this->matcher()->tag($tagged, $replies[1]['id'], null);

        $later = $this->inbound($conversation, 'shippy wen');
        $matches = $this->matcher()->matchThread($conversation->fresh()->load('messages'), $replies);

        $this->assertSame($replies[1]['id'], $matches[$tagged->id]['id']);
        $this->assertSame(InboxQuickReplyMatcher::VIA_TAGGED, $matches[$tagged->id]['via']);
        $this->assertTrue($matches[$tagged->id]['tagged']);
        $this->assertSame($replies[1]['id'], $matches[$later->id]['id']);
        $this->assertSame(InboxQuickReplyMatcher::VIA_EXAMPLE, $matches[$later->id]['via']);
        $this->assertSame(1, InboxQuickReplyExample::query()->count());
    }

    #[Test]
    public function detects_which_product_replies(): void
    {
        $this->assertTrue($this->matcher()->isWhichProductReply([
            'label' => 'কোন প্রডাক্ট?',
            'body' => 'কোন প্রডাক্টটা দেখতে চান?',
        ]));
        $this->assertTrue($this->matcher()->isWhichProductReply([
            'label' => 'Which product?',
            'body' => 'Which product do you mean?',
        ]));
        $this->assertFalse($this->matcher()->isWhichProductReply([
            'label' => 'Price',
            'body' => 'দাম ১২০০ টাকা',
        ]));
    }

    #[Test]
    public function price_query_matches_which_product_only_when_no_customer_photos(): void
    {
        $replies = $this->whichProductReplies();
        $conversation = $this->conversation('psid-qr-which-plain');
        $message = $this->inbound($conversation, 'দাম কত');

        $matches = $this->matcher()->matchThread($conversation->fresh()->load('messages'), $replies);

        $this->assertSame($replies[0]['id'], $matches[$message->id]['id']);
        $this->assertSame(InboxQuickReplyMatcher::VIA_INTENT, $matches[$message->id]['via']);
    }

    #[Test]
    public function price_query_skips_which_product_when_customer_already_sent_photos(): void
    {
        $replies = $this->whichProductReplies();
        $conversation = $this->conversation('psid-qr-which-photo');
        $this->inboundPhoto($conversation, now()->subMinute());
        $message = $this->inbound($conversation, 'এটা কত');

        $matches = $this->matcher()->matchThread($conversation->fresh()->load('messages'), $replies);

        $this->assertArrayNotHasKey($message->id, $matches);
    }

    #[Test]
    public function price_query_falls_through_to_a_price_quote_when_photos_block_which_product(): void
    {
        $replies = $this->whichProductReplies();
        $replies[] = [
            'id' => 'qr_price_quote',
            'label' => 'Price',
            'body' => 'দাম ১২০০ টাকা',
        ];
        $conversation = $this->conversation('psid-qr-which-fallback');
        $this->inboundPhoto($conversation, now()->subMinute());
        $message = $this->inbound($conversation, 'dam koto?');

        $matches = $this->matcher()->matchThread($conversation->fresh()->load('messages'), $replies);

        $this->assertSame('qr_price_quote', $matches[$message->id]['id']);
        $this->assertSame(InboxQuickReplyMatcher::VIA_INTENT, $matches[$message->id]['via']);
    }

    #[Test]
    public function tagged_which_product_stays_even_when_photos_exist(): void
    {
        $replies = $this->whichProductReplies();
        $conversation = $this->conversation('psid-qr-which-tagged');
        $this->inboundPhoto($conversation, now()->subMinute());
        $message = $this->inbound($conversation, 'দাম কত');
        $this->matcher()->tag($message, $replies[0]['id'], null);

        $matches = $this->matcher()->matchThread($conversation->fresh()->load('messages'), $replies);

        $this->assertSame($replies[0]['id'], $matches[$message->id]['id']);
        $this->assertTrue($matches[$message->id]['tagged']);
    }

    #[Test]
    public function example_trained_price_query_skips_which_product_after_photos(): void
    {
        $replies = [
            [
                'id' => 'qr_which_plain',
                'label' => 'কোন প্রডাক্ট?',
                'body' => 'কোন প্রডাক্টটা দেখতে চান?',
            ],
        ];
        $trainer = $this->conversation('psid-qr-which-train');
        $tagged = $this->inbound($trainer, 'দাম কত');
        $this->matcher()->tag($tagged, $replies[0]['id'], null);

        $conversation = $this->conversation('psid-qr-which-later');
        $this->inboundPhoto($conversation, now()->subMinute());
        $later = $this->inbound($conversation, 'দাম কত');

        $matches = $this->matcher()->matchThread($conversation->fresh()->load('messages'), $replies);

        $this->assertArrayNotHasKey($later->id, $matches);
    }

    #[Test]
    public function stickers_do_not_count_as_product_photos(): void
    {
        $replies = $this->whichProductReplies();
        $conversation = $this->conversation('psid-qr-which-sticker');
        ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'media_url' => 'https://example.test/sticker.png',
            'media_mime' => 'image/png',
            'raw_payload' => ['sticker_id' => 'sticker-1'],
            'sent_at' => now()->subMinute(),
        ]);
        $message = $this->inbound($conversation, 'দাম কত');

        $matches = $this->matcher()->matchThread($conversation->fresh()->load('messages'), $replies);

        $this->assertSame($replies[0]['id'], $matches[$message->id]['id']);
    }

    #[Test]
    public function bulk_priced_targets_keep_only_unreplied_matches_above_84(): void
    {
        $conversation = $this->conversation('psid-qr-bulk');
        $high = Product::query()->create([
            'name' => 'High Match',
            'slug' => 'high-match-'.uniqid(),
            'sku' => 'HM1',
            'price' => 900,
            'purchase_price' => 400,
            'stock_quantity' => 2,
            'is_published' => true,
            'display_order' => 0,
        ]);
        $low = Product::query()->create([
            'name' => 'Low Match',
            'slug' => 'low-match-'.uniqid(),
            'sku' => 'LM1',
            'price' => 800,
            'purchase_price' => 300,
            'stock_quantity' => 2,
            'is_published' => true,
            'display_order' => 0,
        ]);
        $edge = Product::query()->create([
            'name' => 'Edge Match',
            'slug' => 'edge-match-'.uniqid(),
            'sku' => 'EM1',
            'price' => 700,
            'purchase_price' => 250,
            'stock_quantity' => 2,
            'is_published' => true,
            'display_order' => 0,
        ]);
        $replied = Product::query()->create([
            'name' => 'Already Replied',
            'slug' => 'replied-match-'.uniqid(),
            'sku' => 'RM1',
            'price' => 600,
            'purchase_price' => 200,
            'stock_quantity' => 2,
            'is_published' => true,
            'display_order' => 0,
        ]);

        $keep = $this->inboundMatchedPhoto($conversation, $high, 0.91, now()->subMinutes(4));
        $this->inboundMatchedPhoto($conversation, $low, 0.80, now()->subMinutes(3));
        $this->inboundMatchedPhoto($conversation, $edge, 0.84, now()->subMinutes(2));
        $done = $this->inboundMatchedPhoto($conversation, $replied, 0.99, now()->subMinutes(1));
        ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_OUTBOUND,
            'body' => 'priced',
            'reply_to_message_id' => $done->id,
            'sent_at' => now(),
        ]);

        $targets = $this->matcher()->bulkPricedImageTargets(
            $conversation->fresh()->load('messages'),
            app(ChannelMessageImageMatchService::class),
        );

        $this->assertSame([$keep->id], array_column($targets, 'message_id'));
        $this->assertSame([$high->id], array_column($targets, 'product_id'));
        $this->assertSame([91.0], array_column($targets, 'match_percent'));
    }

    #[Test]
    public function highlights_priced_image_when_a_nearby_inbound_asks_price(): void
    {
        $replies = $this->replies();
        $conversation = $this->conversation();
        $product = Product::query()->create([
            'name' => 'Silk Kurti',
            'slug' => 'silk-kurti-'.uniqid(),
            'sku' => 'SK1',
            'price' => 1200,
            'purchase_price' => 500,
            'stock_quantity' => 3,
            'is_published' => true,
            'display_order' => 0,
        ]);
        $this->inbound($conversation, 'dam koto?', now()->subMinute());
        $photo = ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'media_url' => 'https://example.test/photo.jpg',
            'media_mime' => 'image/jpeg',
            'matched_product_id' => $product->id,
            'sent_at' => now(),
        ]);

        $thread = $conversation->fresh()->load('messages');
        $matches = $this->matcher()->matchThread($thread, $replies);

        $this->assertTrue($this->matcher()->shouldHighlightPricedImage(
            $photo->fresh(),
            $thread->messages,
            $matches,
        ));
    }

    /**
     * @return list<array{id: string, label: string, body: string}>
     */
    private function replies(): array
    {
        return app(InboxQuickReplyService::class)->normalize([
            ['label' => 'Price', 'body' => 'দাম ১২০০ টাকা'],
            ['label' => 'Delivery', 'body' => '৩-৫ দিনে পৌঁছাবে'],
            ['label' => 'Order', 'body' => 'নাম, ঠিকানা আর মোবাইল দিন'],
        ]);
    }

    /**
     * @return list<array{id: string, label: string, body: string}>
     */
    private function whichProductReplies(): array
    {
        return app(InboxQuickReplyService::class)->normalize([
            ['label' => 'কোন প্রডাক্ট?', 'body' => 'কোন প্রডাক্টের দাম জানতে চাইলে বলুন কোনটা'],
        ]);
    }

    private function matcher(): InboxQuickReplyMatcher
    {
        return app(InboxQuickReplyMatcher::class);
    }

    private function conversation(string $psid = 'psid-qr-match'): ChannelConversation
    {
        return ChannelConversation::query()->create([
            'channel' => ChannelConversation::CHANNEL_MESSENGER,
            'external_user_id' => $psid,
            'customer_name' => 'Karim',
            'last_inbound_at' => now(),
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

    private function inboundMatchedPhoto(
        ChannelConversation $conversation,
        Product $product,
        float $score,
        mixed $sentAt = null,
    ): ChannelMessage {
        return ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'media_url' => 'https://example.test/matched-'.$product->id.'.jpg',
            'media_mime' => 'image/jpeg',
            'matched_product_id' => $product->id,
            'image_match_checked_at' => now(),
            'image_match_meta' => [
                'status' => 'matched',
                'candidates' => [
                    ['product_id' => $product->id, 'score' => $score, 'source' => 'catalog'],
                ],
            ],
            'sent_at' => $sentAt ?? now(),
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
