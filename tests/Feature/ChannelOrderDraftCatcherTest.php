<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use App\Models\City;
use App\Models\Order;
use App\Models\Product;
use App\Services\Channels\ChannelConversationService;
use App\Services\Channels\ChannelOrderDraftCatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChannelOrderDraftCatcherTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'gemini.api_key' => null,
            'channels.ai_draft.catch_on_inbound' => true,
            'facebook.messenger.enabled' => true,
            'facebook.messenger.app_secret' => '',
        ]);
    }

    #[Test]
    public function catcher_creates_a_draft_when_the_intent_gate_passes(): void
    {
        $product = $this->product();
        $this->seedDhakaMirpur();
        $conversation = $this->conversation('PSID_CATCH');

        ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'media_url' => 'https://example.test/selected.jpg',
            'media_mime' => 'image/jpeg',
            'matched_product_id' => $product->id,
            'sent_at' => now()->subMinutes(2),
        ]);
        ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => "Nila\n01627237432\nMirpur, Dhaka",
            'sent_at' => now(),
        ]);

        $draft = app(ChannelOrderDraftCatcher::class)->catch($conversation->fresh());

        $this->assertNotNull($draft);
        $this->assertTrue($draft->isAiDraft());
        $this->assertSame('intent_gate', $draft->ai_parse_meta['caught_via'] ?? null);
        $this->assertSame($product->id, (int) $draft->items->first()?->product_id);
        $this->assertSame('01627237432', $draft->phone);
        $this->assertSame($conversation->fresh()->draft_order_id, $draft->id);
    }

    #[Test]
    public function catcher_does_not_create_a_draft_for_chatter(): void
    {
        $conversation = $this->conversation('PSID_CHATTER');
        ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => 'দাম কত apu',
            'media_url' => 'https://example.test/browse.jpg',
            'media_mime' => 'image/jpeg',
            'sent_at' => now(),
        ]);

        $this->assertNull(app(ChannelOrderDraftCatcher::class)->catch($conversation->fresh()));
        $this->assertSame(0, Order::query()->where('status', Order::STATUS_DRAFT)->count());
    }

    #[Test]
    public function inbound_store_still_skips_text_only_closes_without_a_product_photo(): void
    {
        $body = json_encode([
            'object' => 'page',
            'entry' => [[
                'id' => 'PAGE123',
                'time' => time(),
                'messaging' => [[
                    'sender' => ['id' => 'PSID_TEXT_CLOSE'],
                    'recipient' => ['id' => 'PAGE123'],
                    'timestamp' => (int) (microtime(true) * 1000),
                    'message' => [
                        'mid' => 'm_text_close',
                        'text' => "Rahim\n01627237432\nHouse 12, Dhanmondi, Dhaka\nSilk Kurti",
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

        $this->assertSame(0, Order::query()->where('status', Order::STATUS_DRAFT)->count());
    }

    #[Test]
    public function inbound_store_catches_a_close_after_a_tagged_photo(): void
    {
        $product = $this->product();
        $this->seedDhakaMirpur();
        $conversation = $this->conversation('PSID999-CATCH');

        ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'media_url' => 'https://example.test/selected.jpg',
            'media_mime' => 'image/jpeg',
            'matched_product_id' => $product->id,
            'sent_at' => now()->subMinute(),
        ]);

        app(ChannelConversationService::class)->storeMessage($conversation, [
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => "Nila\n01627237432\nMirpur, Dhaka",
            'sent_at' => now(),
        ]);

        $draft = app(ChannelOrderDraftCatcher::class)->catch($conversation->fresh());

        $this->assertNotNull($draft);
        $this->assertTrue($draft->isAiDraft());
        $this->assertSame($product->id, (int) $draft->items->first()?->product_id);
    }

    private function conversation(string $psid): ChannelConversation
    {
        return ChannelConversation::query()->create([
            'channel' => ChannelConversation::CHANNEL_MESSENGER,
            'external_user_id' => $psid,
            'last_inbound_at' => now(),
        ]);
    }

    private function product(): Product
    {
        return Product::query()->create([
            'name' => 'Silk Kurti',
            'slug' => 'silk-kurti-catch-'.uniqid(),
            'sku' => 'CK'.random_int(100, 999),
            'price' => 1200,
            'purchase_price' => 500,
            'stock_quantity' => 10,
            'is_published' => true,
            'display_order' => 0,
        ]);
    }

    private function seedDhakaMirpur(): void
    {
        $city = City::query()->firstOrCreate(
            ['name' => 'Dhaka'],
            [
                'division' => null,
                'is_dhaka' => true,
                'is_active' => true,
                'slug' => 'dhaka-'.uniqid(),
            ],
        );

        Area::query()->firstOrCreate(
            ['city_id' => $city->id, 'name' => 'Mirpur'],
            [
                'police_station' => null,
                'is_active' => true,
                'unit_type' => 'thana',
                'slug' => 'mirpur-'.uniqid(),
                'delivery_charge_upto_5' => 80,
                'delivery_charge_over_5' => 150,
            ],
        );
    }
}
