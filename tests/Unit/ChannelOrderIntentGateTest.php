<?php

namespace Tests\Unit;

use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use App\Models\Product;
use App\Services\Channels\ChannelOrderIntentGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChannelOrderIntentGateTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function price_question_with_photos_does_not_pass(): void
    {
        $conversation = $this->conversation();
        $this->inbound($conversation, [
            'body' => 'দাম কত',
            'media_url' => 'https://example.test/browse.jpg',
            'media_mime' => 'image/jpeg',
        ]);

        $this->assertFalse(app(ChannelOrderIntentGate::class)->passes($conversation));
    }

    #[Test]
    public function phone_and_address_without_a_selected_product_do_not_pass(): void
    {
        $conversation = $this->conversation();
        $this->inbound($conversation, [
            'body' => "Rahim\n01627237432\nHouse 12, Dhanmondi, Dhaka",
        ]);

        $this->assertFalse(app(ChannelOrderIntentGate::class)->passes($conversation));
    }

    #[Test]
    public function close_with_tagged_photo_phone_and_address_passes(): void
    {
        $product = $this->product();
        $conversation = $this->conversation();
        $this->inbound($conversation, [
            'body' => null,
            'media_url' => 'https://example.test/selected.jpg',
            'media_mime' => 'image/jpeg',
            'matched_product_id' => $product->id,
            'sent_at' => now()->subMinutes(2),
        ]);
        $this->inbound($conversation, [
            'body' => "Rahim\n01627237432\nHouse 12, Dhanmondi, Dhaka",
        ]);

        $this->assertTrue(app(ChannelOrderIntentGate::class)->passes($conversation));
    }

    #[Test]
    public function text_reply_to_an_outbound_priced_image_with_phone_and_name_passes(): void
    {
        $conversation = $this->conversation();
        $priced = ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_OUTBOUND,
            'body' => '১,২০০ টাকা',
            'media_url' => 'https://example.test/priced.jpg',
            'media_mime' => 'image/jpeg',
            'sent_at' => now()->subMinutes(3),
        ]);
        $this->inbound($conversation, [
            'body' => "Nila\n01627237432\nMirpur, Dhaka",
            'reply_to_message_id' => $priced->id,
        ]);

        $this->assertTrue(app(ChannelOrderIntentGate::class)->passes($conversation));
    }

    private function conversation(): ChannelConversation
    {
        return ChannelConversation::query()->create([
            'channel' => ChannelConversation::CHANNEL_MESSENGER,
            'external_user_id' => 'psid-intent-gate',
            'customer_name' => 'Shayamoli Hossain',
            'last_inbound_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function inbound(ChannelConversation $conversation, array $overrides = []): ChannelMessage
    {
        return ChannelMessage::query()->create(array_merge([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => 'hello',
            'sent_at' => now(),
        ], $overrides));
    }

    private function product(): Product
    {
        return Product::query()->create([
            'name' => 'Silk Kurti',
            'slug' => 'silk-kurti-gate-'.uniqid(),
            'sku' => 'GK'.random_int(100, 999),
            'price' => 1200,
            'purchase_price' => 500,
            'stock_quantity' => 10,
            'is_published' => true,
            'display_order' => 0,
        ]);
    }
}
