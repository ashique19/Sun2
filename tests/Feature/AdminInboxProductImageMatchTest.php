<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminInbox;
use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\Admin\ProductImageHashService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminInboxProductImageMatchTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function conversation(): ChannelConversation
    {
        return ChannelConversation::query()->create([
            'channel' => ChannelConversation::CHANNEL_MESSENGER,
            'external_user_id' => 'psid-crop-1',
            'customer_name' => 'Crop Customer',
            'last_inbound_at' => now(),
        ]);
    }

    private function productWithHash(string $hash): Product
    {
        $product = Product::query()->create([
            'name' => 'Crop Match Saree',
            'slug' => 'crop-match-saree-'.uniqid(),
            'sku' => 'CMS'.random_int(100, 999),
            'price' => 1800,
            'purchase_price' => 900,
            'stock_quantity' => 5,
            'is_published' => true,
            'display_order' => 0,
        ]);

        ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => 'products/crop-match.jpg',
            'alt' => $product->name,
            'sort_order' => 0,
            'perceptual_hash' => $hash,
        ]);

        return $product;
    }

    #[Test]
    public function opening_map_menu_keeps_field_picker_ready_until_product_modal(): void
    {
        $this->actingAs($this->adminUser());
        $conversation = $this->conversation();

        $message = ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => null,
            'media_url' => 'https://example.test/chat-menu.jpg',
            'media_mime' => 'image/jpeg',
            'sent_at' => now(),
        ]);

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->assertDontSee('Open full size')
            ->assertDontSee('Match product')
            ->assertSee('+ Order')
            ->call('openMessageMapMenu', $message->id)
            ->assertSet('mappingMessageId', $message->id)
            ->assertSet('mappingField', null)
            ->assertSee('Add to order fields')
            ->assertSee('Products')
            ->call('beginMapField', 'product')
            ->assertSet('mappingField', 'product')
            ->assertSet('mappingMode', 'order')
            ->assertSeeHtml('data-inbox-product-map-modal')
            ->assertSeeHtml('z-index: 100000')
            ->assertSeeHtml('max-height: calc(100svh - 1rem)')
            ->assertDontSee('load older messages');
    }

    #[Test]
    public function search_icon_opens_tag_product_modal_for_image_message(): void
    {
        $this->actingAs($this->adminUser());
        $conversation = $this->conversation();

        $message = ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => null,
            'media_url' => 'https://example.test/chat-direct.jpg',
            'media_mime' => 'image/jpeg',
            'sent_at' => now(),
        ]);

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->call('openTagProductOnImage', $message->id)
            ->assertSet('mappingMessageId', $message->id)
            ->assertSet('mappingField', 'product')
            ->assertSet('mappingMode', 'tag')
            ->assertSee('Tag product on photo')
            ->assertSee('Crop chat image')
            ->assertSeeHtml('min(52vh, 26rem)')
            ->assertDontSee('load older messages');
    }

    #[Test]
    public function tagging_a_product_on_image_persists_without_creating_order(): void
    {
        $this->actingAs($this->adminUser());
        $conversation = $this->conversation();
        $product = Product::query()->create([
            'name' => 'Tagged Ring',
            'slug' => 'tagged-ring-'.uniqid(),
            'sku' => 'TR'.random_int(100, 999),
            'price' => 900,
            'purchase_price' => 400,
            'stock_quantity' => 4,
            'is_published' => true,
            'display_order' => 0,
        ]);

        $message = ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'media_url' => 'https://example.test/tag-me.jpg',
            'media_mime' => 'image/jpeg',
            'sent_at' => now(),
        ]);

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->call('openTagProductOnImage', $message->id)
            ->call('tagMatchedProduct', $product->id)
            ->assertSet('mappingField', null)
            ->assertSee('Tagged Ring')
            ->assertSeeHtml('href="'.route('admin.products.show', $product).'"')
            ->assertSeeHtml('target="_blank"')
            ->assertSee('Send priced')
            ->assertSee('Add to order')
            ->call('addMatchedProductToOrder', $message->id)
            ->assertSet('orderPanelOpen', true);

        $this->assertSame($product->id, $message->fresh()->matched_product_id);
        $order = Order::query()->find($conversation->fresh()->draft_order_id);
        $this->assertSame($product->id, $order?->items()->first()?->product_id);
    }

    #[Test]
    public function product_mapping_modal_opens_with_catalog_search(): void
    {
        $this->actingAs($this->adminUser());
        $conversation = $this->conversation();
        $product = Product::query()->create([
            'name' => 'Silk Kurti Search',
            'slug' => 'silk-kurti-search-'.uniqid(),
            'sku' => 'SKS'.random_int(100, 999),
            'price' => 1200,
            'purchase_price' => 500,
            'stock_quantity' => 8,
            'is_published' => true,
            'display_order' => 0,
        ]);

        $message = ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => 'Silk Kurti Search please',
            'sent_at' => now(),
        ]);

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->call('openMessageMapMenu', $message->id)
            ->call('beginMapField', 'product')
            ->assertSet('mappingField', 'product')
            ->assertSee('Add product to order')
            ->assertSee('Search products')
            ->assertSeeHtml('data-inbox-product-map-modal')
            ->assertSeeHtml('max-w-xl')
            ->set('mappingProductSearch', 'Silk Kurti')
            ->assertSee('Silk Kurti Search')
            ->assertSet('mappingField', 'product')
            ->call('applyMapField', 'product', $product->id)
            ->assertSet('mappingField', null);

        $order = Order::query()->find($conversation->fresh()->draft_order_id);
        $this->assertNotNull($order);
        $this->assertSame($product->id, $order->items()->first()?->product_id);
    }

    #[Test]
    public function cropped_image_lists_strong_catalog_match_for_manual_confirm(): void
    {
        Storage::fake('public');
        $this->actingAs($this->adminUser());

        $hash = str_repeat('a', 16);
        $product = $this->productWithHash($hash);
        $conversation = $this->conversation();

        $message = ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => null,
            'media_url' => 'https://example.test/chat.jpg',
            'media_mime' => 'image/jpeg',
            'raw_payload' => [
                'message' => [
                    'attachments' => [
                        ['type' => 'image', 'payload' => ['url' => 'https://example.test/chat.jpg']],
                    ],
                ],
            ],
            'sent_at' => now(),
        ]);

        $hasher = \Mockery::mock(ProductImageHashService::class);
        $hasher->shouldReceive('hashUploadedFile')
            ->once()
            ->andReturn($hash);
        $hasher->shouldReceive('findTopMatches')
            ->once()
            ->andReturn([[
                'product_id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'price' => (float) $product->price,
                'stock_quantity' => (int) $product->stock_quantity,
                'image_url' => null,
                'match_percent' => 95.0,
                'distance' => 3,
            ]]);
        $this->app->instance(ProductImageHashService::class, $hasher);

        $upload = UploadedFile::fake()->image('inbox-crop.jpg', 200, 200);

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->call('openMessageMapMenu', $message->id)
            ->call('beginMapField', 'product')
            ->assertSee('Crop chat image')
            ->assertSet('mappingMode', 'order')
            ->set('mappingCroppedImage', $upload)
            ->call('matchProductFromCroppedImage')
            ->assertSet('mappingField', 'product')
            ->assertSee('Image matches')
            ->assertSee('95.0% match')
            ->call('selectMappingImageMatch', $product->id)
            ->assertSet('mappingField', null);

        $order = Order::query()->with('items')->find($conversation->fresh()->draft_order_id);
        $this->assertSame($product->id, $order?->items->first()?->product_id);
    }

    #[Test]
    public function weaker_cropped_matches_are_listed_for_staff_choice(): void
    {
        $this->actingAs($this->adminUser());

        $hash = str_repeat('b', 16);
        $product = $this->productWithHash($hash);
        $conversation = $this->conversation();

        $message = ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => null,
            'media_url' => 'https://example.test/chat2.jpg',
            'media_mime' => 'image/jpeg',
            'sent_at' => now(),
        ]);

        $hasher = \Mockery::mock(ProductImageHashService::class);
        $hasher->shouldReceive('hashUploadedFile')->once()->andReturn($hash);
        $hasher->shouldReceive('findTopMatches')->once()->andReturn([[
            'product_id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'price' => (float) $product->price,
            'stock_quantity' => (int) $product->stock_quantity,
            'image_url' => null,
            'match_percent' => 84.0,
            'distance' => 10,
        ]]);
        $this->app->instance(ProductImageHashService::class, $hasher);

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->call('openMessageMapMenu', $message->id)
            ->call('beginMapField', 'product')
            ->set('mappingCroppedImage', UploadedFile::fake()->image('inbox-crop.jpg'))
            ->call('matchProductFromCroppedImage')
            ->assertSet('mappingField', 'product')
            ->assertSee('Image matches')
            ->assertSee('84.0% match')
            ->call('selectMappingImageMatch', $product->id)
            ->assertSet('mappingField', null);

        $this->assertSame(
            $product->id,
            Order::query()->find($conversation->fresh()->draft_order_id)?->items()->first()?->product_id,
        );
    }
}
