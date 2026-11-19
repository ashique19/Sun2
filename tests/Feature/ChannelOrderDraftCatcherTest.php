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
use App\Services\Channels\ChannelOrderDraftService;
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
        $this->assertSame('Nila', $draft->name);
        $this->assertSame($conversation->fresh()->draft_order_id, $draft->id);
    }

    #[Test]
    public function catcher_adds_each_tagged_photo_as_a_line_item(): void
    {
        $first = $this->product(['name' => 'Gold Set', 'price' => 1650]);
        $second = $this->product(['name' => 'Silver Pair', 'price' => 650]);
        $third = $this->product(['name' => 'Nupur', 'price' => 1200]);
        $this->seedDhakaMirpur();
        $conversation = $this->conversation('PSID_MULTI_PRODUCT');

        foreach ([$first, $second, $third] as $offset => $product) {
            ChannelMessage::query()->create([
                'channel_conversation_id' => $conversation->id,
                'direction' => ChannelMessage::DIRECTION_INBOUND,
                'media_url' => 'https://example.test/p'.$offset.'.jpg',
                'media_mime' => 'image/jpeg',
                'matched_product_id' => $product->id,
                'sent_at' => now()->subMinutes(5 - $offset),
            ]);
        }
        ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => "Ujerung tripura,khagrachari,panchari,kuradiya tarabonchara,\n01857120756",
            'sent_at' => now(),
        ]);

        $draft = app(ChannelOrderDraftCatcher::class)->catch($conversation->fresh());

        $this->assertNotNull($draft);
        $this->assertSame(
            [$first->id, $second->id, $third->id],
            $draft->items()->orderBy('id')->pluck('product_id')->all(),
        );
        $this->assertSame([1, 1, 1], $draft->items()->orderBy('id')->pluck('quantity')->all());
        $this->assertSame(1650 + 650 + 1200, (int) $draft->subtotal);
    }

    #[Test]
    public function catcher_increments_quantity_when_the_same_product_is_tagged_twice(): void
    {
        $pair = $this->product(['name' => 'Silver Pair', 'price' => 650]);
        $set = $this->product(['name' => 'Gold Set', 'price' => 1650]);
        $this->seedDhakaMirpur();
        $conversation = $this->conversation('PSID_QTY_PRODUCT');

        foreach ([$pair, $pair, $set] as $offset => $product) {
            ChannelMessage::query()->create([
                'channel_conversation_id' => $conversation->id,
                'direction' => ChannelMessage::DIRECTION_INBOUND,
                'media_url' => 'https://example.test/q'.$offset.'.jpg',
                'media_mime' => 'image/jpeg',
                'matched_product_id' => $product->id,
                'sent_at' => now()->subMinutes(4 - $offset),
            ]);
        }
        ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => "Sathi Mondal\nMunshirhat\nChauddagram\nCumilla\n01756808261",
            'sent_at' => now(),
        ]);

        $draft = app(ChannelOrderDraftCatcher::class)->catch($conversation->fresh());

        $this->assertNotNull($draft);
        $items = $draft->items()->orderBy('id')->get();
        $this->assertCount(2, $items);
        $this->assertSame($pair->id, (int) $items[0]->product_id);
        $this->assertSame(2, (int) $items[0]->quantity);
        $this->assertSame(650 * 2, (int) $items[0]->line_total);
        $this->assertSame($set->id, (int) $items[1]->product_id);
        $this->assertSame(1, (int) $items[1]->quantity);
    }

    #[Test]
    public function catcher_uses_typed_name_not_the_facebook_profile_name(): void
    {
        $product = $this->product();
        $this->seedDhakaMirpur();
        $conversation = $this->conversation('PSID_TYPED_NAME');
        $conversation->forceFill(['customer_name' => 'Hamida Ahmed'])->save();

        ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'media_url' => 'https://example.test/selected.jpg',
            'media_mime' => 'image/jpeg',
            'matched_product_id' => $product->id,
            'sent_at' => now()->subMinutes(6),
        ]);
        foreach ([
            'parben??',
            'Tanzima khan',
            'Shohag clinic, puran building, rajapur, jhalokathi',
            '01883104511',
            'Order confirm kore rakhen',
        ] as $offset => $body) {
            ChannelMessage::query()->create([
                'channel_conversation_id' => $conversation->id,
                'direction' => ChannelMessage::DIRECTION_INBOUND,
                'body' => $body,
                'sent_at' => now()->subMinutes(5 - $offset),
            ]);
        }

        $draft = app(ChannelOrderDraftCatcher::class)->catch($conversation->fresh());

        $this->assertNotNull($draft);
        $this->assertSame('Tanzima khan', $draft->name);
        $this->assertSame('01883104511', $draft->phone);
        $this->assertSame('Shohag clinic, puran building, rajapur, jhalokathi', $draft->address);
        $this->assertSame('jhalokathi', $draft->city);
        $this->assertSame('rajapur', $draft->area);
        $this->assertNotSame('Hamida Ahmed', $draft->name);
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

    #[Test]
    public function catcher_skips_draft_and_notifies_when_a_new_order_already_exists(): void
    {
        $product = $this->product();
        $this->seedDhakaMirpur();
        $conversation = $this->conversation('PSID_CATCH_NEW');
        $existing = $this->openOrder($conversation, 'new', '88001');

        $this->closeMessages($conversation, $product);

        $this->assertNull(app(ChannelOrderDraftCatcher::class)->catch($conversation->fresh()));
        $this->assertSame(0, Order::query()->where('status', Order::STATUS_DRAFT)->count());

        $conversation->refresh();
        $block = $conversation->draftCatchBlock();
        $this->assertNotNull($block);
        $this->assertSame($existing->id, $block['order_id']);
        $this->assertSame('New', $block['label']);
        $this->assertSame('88001', $block['order_number']);

        $summary = app(ChannelOrderDraftCatcher::class)->blockedSummary($conversation);
        $this->assertSame($existing->id, $summary['order_id'] ?? null);
        $this->assertSame('New', $summary['label'] ?? null);
    }

    #[Test]
    public function catcher_skips_draft_when_a_dispatched_order_exists_for_the_same_phone(): void
    {
        $product = $this->product();
        $this->seedDhakaMirpur();
        $conversation = $this->conversation('PSID_CATCH_DISPATCH');
        $existing = $this->openOrder(null, 'dispatched', '88002', '01627237432');

        $this->closeMessages($conversation, $product);

        $this->assertNull(app(ChannelOrderDraftCatcher::class)->catch($conversation->fresh()));
        $this->assertSame(0, Order::query()->where('status', Order::STATUS_DRAFT)->count());

        $block = $conversation->fresh()->draftCatchBlock();
        $this->assertSame($existing->id, $block['order_id'] ?? null);
        $this->assertSame('Dispatched', $block['label'] ?? null);
    }

    #[Test]
    public function catcher_treats_confirmed_as_a_new_order_and_skips_the_draft(): void
    {
        $product = $this->product();
        $this->seedDhakaMirpur();
        $conversation = $this->conversation('PSID_CATCH_CONFIRMED');
        $this->openOrder($conversation, 'confirmed', '88004');
        $this->closeMessages($conversation, $product);

        $this->assertNull(app(ChannelOrderDraftCatcher::class)->catch($conversation->fresh()));
        $this->assertSame('New', $conversation->fresh()->draftCatchBlock()['label'] ?? null);
        $this->assertSame(0, Order::query()->where('status', Order::STATUS_DRAFT)->count());
    }

    #[Test]
    public function staff_can_still_start_a_draft_when_a_new_order_exists(): void
    {
        $conversation = $this->conversation('PSID_CATCH_STAFF');
        $this->openOrder($conversation, 'new', '88005');

        $draft = app(ChannelOrderDraftService::class)->ensureDraftForConversation($conversation);

        $this->assertTrue($draft->isAiDraft());
        $this->assertSame($conversation->fresh()->draft_order_id, $draft->id);
    }

    #[Test]
    public function catcher_still_drafts_when_the_only_order_is_delivered(): void
    {
        $product = $this->product();
        $this->seedDhakaMirpur();
        $conversation = $this->conversation('PSID_CATCH_DELIVERED');
        $this->openOrder($conversation, 'delivered', '88003');

        $this->closeMessages($conversation, $product);

        $draft = app(ChannelOrderDraftCatcher::class)->catch($conversation->fresh());

        $this->assertNotNull($draft);
        $this->assertTrue($draft->isAiDraft());
        $this->assertNull($conversation->fresh()->draftCatchBlock());
    }

    private function closeMessages(ChannelConversation $conversation, Product $product): void
    {
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
    }

    private function openOrder(
        ?ChannelConversation $conversation,
        string $status,
        string $orderNumber,
        string $phone = '01627237432',
    ): Order {
        return Order::query()->create([
            'order_number' => $orderNumber,
            'name' => 'Nila',
            'phone' => $phone,
            'address' => 'Mirpur',
            'city' => 'Dhaka',
            'area' => 'Mirpur',
            'subtotal' => 1200,
            'delivery_charge' => 80,
            'discount' => 0,
            'total' => 1280,
            'cod_amount' => 1280,
            'due_amount' => 1280,
            'payment_status' => 'unpaid',
            'payment_method' => 'cod',
            'status' => $status,
            'placed_at' => now()->subDay(),
            'placed_via' => Order::PLACED_VIA_MESSENGER,
            'channel_conversation_id' => $conversation?->id,
        ]);
    }

    private function conversation(string $psid): ChannelConversation
    {
        return ChannelConversation::query()->create([
            'channel' => ChannelConversation::CHANNEL_MESSENGER,
            'external_user_id' => $psid,
            'last_inbound_at' => now(),
        ]);
    }

    private function product(array $overrides = []): Product
    {
        return Product::query()->create(array_merge([
            'name' => 'Silk Kurti',
            'slug' => 'silk-kurti-catch-'.uniqid(),
            'sku' => 'CK'.random_int(100, 999),
            'price' => 1200,
            'purchase_price' => 500,
            'stock_quantity' => 10,
            'is_published' => true,
            'display_order' => 0,
        ], $overrides));
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
