<?php

namespace Tests\Unit;

use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use App\Models\InboxQuickReplyExample;
use App\Models\Product;
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

    private function matcher(): InboxQuickReplyMatcher
    {
        return app(InboxQuickReplyMatcher::class);
    }

    private function conversation(): ChannelConversation
    {
        return ChannelConversation::query()->create([
            'channel' => ChannelConversation::CHANNEL_MESSENGER,
            'external_user_id' => 'psid-qr-match',
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
