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
use App\Services\Channels\ChannelMessageImageMatchService;
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
            ->assertDontSeeHtml('@click.stop="openMenu()"')
            ->assertSeeHtml('orderMapEnabled: false')
            ->assertSeeHtml('wire:click="toggleOrderPanel"');
    }

    #[Test]
    public function text_messages_still_show_message_level_order_button(): void
    {
        $this->actingAs($this->adminUser());
        $conversation = $this->conversation();

        ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => 'Please send to Gulshan',
            'sent_at' => now(),
        ]);

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->assertSeeHtml('@click.stop="openMenu()"')
            ->assertSeeHtml('orderMapEnabled: true')
            ->assertSee('Add to order fields')
            ->assertSee('Products');
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
            ->assertSee('Price')
            ->assertSee('P.img')
            ->assertSee('A.Img')
            ->assertSee('Link')
            ->assertSee('+Order')
            ->assertSeeHtml('wire:click="sendMatchedProductPriceReply('.$message->id.')"')
            ->assertSeeHtml('wire:click="sendMatchedProductAlbumImages('.$message->id.')"')
            ->assertSeeHtml('wire:click="sendMatchedProductLink('.$message->id.')"')
            ->assertSeeHtml('wire:click="addMatchedProductToOrder('.$message->id.')"')
            ->assertDontSeeHtml('@click.stop="openMenu()"')
            ->call('addMatchedProductToOrder', $message->id)
            ->assertSet('orderPanelOpen', true);

        $this->assertSame($product->id, $message->fresh()->matched_product_id);
        $order = Order::query()->find($conversation->fresh()->draft_order_id);
        $this->assertSame($product->id, $order?->items()->first()?->product_id);
    }

    #[Test]
    public function adding_matched_products_from_conversation_appends_draft_line_items(): void
    {
        $this->actingAs($this->adminUser());
        $conversation = $this->conversation();

        $first = Product::query()->create([
            'name' => 'First Matched Ring',
            'slug' => 'first-matched-ring-'.uniqid(),
            'sku' => 'FMR'.random_int(100, 999),
            'price' => 1100,
            'purchase_price' => 500,
            'stock_quantity' => 3,
            'is_published' => true,
            'display_order' => 0,
        ]);
        $second = Product::query()->create([
            'name' => 'Second Matched Necklace',
            'slug' => 'second-matched-necklace-'.uniqid(),
            'sku' => 'SMN'.random_int(100, 999),
            'price' => 2200,
            'purchase_price' => 900,
            'stock_quantity' => 2,
            'is_published' => true,
            'display_order' => 0,
        ]);

        $firstMessage = ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'media_url' => 'https://example.test/first.jpg',
            'media_mime' => 'image/jpeg',
            'sent_at' => now()->subMinutes(2),
        ]);
        $secondMessage = ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'media_url' => 'https://example.test/second.jpg',
            'media_mime' => 'image/jpeg',
            'sent_at' => now()->subMinute(),
        ]);

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->call('openTagProductOnImage', $firstMessage->id)
            ->call('tagMatchedProduct', $first->id)
            ->call('addMatchedProductToOrder', $firstMessage->id)
            ->call('openTagProductOnImage', $secondMessage->id)
            ->call('tagMatchedProduct', $second->id)
            ->call('addMatchedProductToOrder', $secondMessage->id)
            ->assertHasNoErrors();

        $order = Order::query()->find($conversation->fresh()->draft_order_id);
        $this->assertNotNull($order);
        $productIds = $order->items()->orderBy('id')->pluck('product_id')->all();
        $this->assertSame([$first->id, $second->id], $productIds);
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

    #[Test]
    public function find_top_matches_excludes_unpublished_catalog_products(): void
    {
        $sharedHash = str_repeat('c', 16);
        $published = $this->productWithHash($sharedHash);

        $unpublished = Product::query()->create([
            'name' => 'Unpublished Crop Twin',
            'slug' => 'unpublished-crop-twin-'.uniqid(),
            'sku' => 'UCT'.random_int(100, 999),
            'price' => 1500,
            'purchase_price' => 700,
            'stock_quantity' => 2,
            'is_published' => false,
            'display_order' => 0,
        ]);
        ProductImage::query()->create([
            'product_id' => $unpublished->id,
            'path' => 'products/unpublished-crop-twin.jpg',
            'alt' => $unpublished->name,
            'sort_order' => 0,
            'perceptual_hash' => $sharedHash,
        ]);

        $onlyUnpublishedHash = str_repeat('d', 16);
        $onlyUnpublished = Product::query()->create([
            'name' => 'Only Unpublished Match',
            'slug' => 'only-unpublished-match-'.uniqid(),
            'sku' => 'OUM'.random_int(100, 999),
            'price' => 1200,
            'purchase_price' => 500,
            'stock_quantity' => 1,
            'is_published' => false,
            'display_order' => 0,
        ]);
        ProductImage::query()->create([
            'product_id' => $onlyUnpublished->id,
            'path' => 'products/only-unpublished.jpg',
            'alt' => $onlyUnpublished->name,
            'sort_order' => 0,
            'perceptual_hash' => $onlyUnpublishedHash,
        ]);

        $hasher = app(ProductImageHashService::class);

        $sharedMatches = $hasher->findTopMatches($sharedHash, 5, ProductImageHashService::MIN_MATCH_PERCENT);
        $sharedIds = array_map(fn (array $m) => $m['product_id'], $sharedMatches);
        $this->assertContains($published->id, $sharedIds);
        $this->assertNotContains($unpublished->id, $sharedIds);

        $this->assertSame(
            [],
            $hasher->findTopMatches($onlyUnpublishedHash, 5, ProductImageHashService::MIN_MATCH_PERCENT),
        );
    }

    #[Test]
    public function crop_tag_lists_published_match_and_skips_unpublished_twin(): void
    {
        $this->actingAs($this->adminUser());

        $hash = str_repeat('e', 16);
        $published = $this->productWithHash($hash);
        $published->forceFill(['name' => 'Published Tag Crop'])->save();

        $unpublished = Product::query()->create([
            'name' => 'Unpublished Tag Crop',
            'slug' => 'unpublished-tag-crop-'.uniqid(),
            'sku' => 'UTC'.random_int(100, 999),
            'price' => 1600,
            'purchase_price' => 800,
            'stock_quantity' => 3,
            'is_published' => false,
            'display_order' => 0,
        ]);
        ProductImage::query()->create([
            'product_id' => $unpublished->id,
            'path' => 'products/unpublished-tag-crop.jpg',
            'alt' => $unpublished->name,
            'sort_order' => 0,
            'perceptual_hash' => $hash,
        ]);

        $conversation = $this->conversation();
        $message = ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => null,
            'media_url' => 'https://example.test/tag-crop.jpg',
            'media_mime' => 'image/jpeg',
            'sent_at' => now(),
        ]);

        $real = app(ProductImageHashService::class);
        $hasher = \Mockery::mock(ProductImageHashService::class);
        $hasher->shouldReceive('hashUploadedFile')->once()->andReturn($hash);
        $hasher->shouldReceive('findTopMatches')
            ->once()
            ->andReturnUsing(fn (string $h, int $limit = 5, float $min = 80.0) => $real->findTopMatches($h, $limit, $min));
        $this->app->instance(ProductImageHashService::class, $hasher);

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->call('openTagProductOnImage', $message->id)
            ->assertSet('mappingMode', 'tag')
            ->set('mappingCroppedImage', UploadedFile::fake()->image('tag-crop.jpg', 200, 200))
            ->call('matchProductFromCroppedImage')
            ->assertSee('Published Tag Crop')
            ->assertDontSee('Unpublished Tag Crop')
            ->assertSee('Tag')
            ->call('selectMappingImageMatch', $published->id)
            ->assertSet('error', null)
            ->assertSee('Tagged');

        $this->assertSame($published->id, $message->fresh()->matched_product_id);
        $this->assertNull($conversation->fresh()->draft_order_id);
    }

    #[Test]
    public function crop_tag_shows_no_match_when_only_unpublished_catalog_hits(): void
    {
        $this->actingAs($this->adminUser());

        $hash = str_repeat('f', 16);
        $unpublished = Product::query()->create([
            'name' => 'Ghost Unpublished Crop',
            'slug' => 'ghost-unpublished-crop-'.uniqid(),
            'sku' => 'GUC'.random_int(100, 999),
            'price' => 1400,
            'purchase_price' => 600,
            'stock_quantity' => 1,
            'is_published' => false,
            'display_order' => 0,
        ]);
        ProductImage::query()->create([
            'product_id' => $unpublished->id,
            'path' => 'products/ghost-unpublished.jpg',
            'alt' => $unpublished->name,
            'sort_order' => 0,
            'perceptual_hash' => $hash,
        ]);

        $conversation = $this->conversation();
        $message = ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'media_url' => 'https://example.test/ghost-crop.jpg',
            'media_mime' => 'image/jpeg',
            'sent_at' => now(),
        ]);

        $real = app(ProductImageHashService::class);
        $hasher = \Mockery::mock(ProductImageHashService::class);
        $hasher->shouldReceive('hashUploadedFile')->once()->andReturn($hash);
        $hasher->shouldReceive('findTopMatches')
            ->once()
            ->andReturnUsing(fn (string $h, int $limit = 5, float $min = 80.0) => $real->findTopMatches($h, $limit, $min));
        $this->app->instance(ProductImageHashService::class, $hasher);

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->call('openTagProductOnImage', $message->id)
            ->set('mappingCroppedImage', UploadedFile::fake()->image('ghost-crop.jpg', 200, 200))
            ->call('matchProductFromCroppedImage')
            ->assertSet('mappingImageMatches', [])
            ->assertSee('No catalog match at 80%+')
            ->assertDontSee('Ghost Unpublished Crop');

        $this->assertNull($message->fresh()->matched_product_id);
    }

    #[Test]
    public function product_name_search_in_map_modal_excludes_unpublished(): void
    {
        $this->actingAs($this->adminUser());
        $conversation = $this->conversation();

        Product::query()->create([
            'name' => 'Visible Gold Ring',
            'slug' => 'visible-gold-ring-'.uniqid(),
            'sku' => 'VGR'.random_int(100, 999),
            'price' => 900,
            'purchase_price' => 400,
            'stock_quantity' => 4,
            'is_published' => true,
            'display_order' => 0,
        ]);
        Product::query()->create([
            'name' => 'Hidden Gold Ring',
            'slug' => 'hidden-gold-ring-'.uniqid(),
            'sku' => 'HGR'.random_int(100, 999),
            'price' => 950,
            'purchase_price' => 420,
            'stock_quantity' => 2,
            'is_published' => false,
            'display_order' => 0,
        ]);

        $message = ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'media_url' => 'https://example.test/search-tag.jpg',
            'media_mime' => 'image/jpeg',
            'sent_at' => now(),
        ]);

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->call('openTagProductOnImage', $message->id)
            ->set('mappingProductSearch', 'Gold Ring')
            ->assertSee('Visible Gold Ring')
            ->assertDontSee('Hidden Gold Ring');
    }

    #[Test]
    public function crop_suggestion_endpoint_returns_trim_bounds_for_screenshot(): void
    {
        $this->actingAs($this->adminUser());

        $catalog = imagecreatetruecolor(200, 200);
        $a = imagecolorallocate($catalog, 200, 40, 60);
        $b = imagecolorallocate($catalog, 40, 90, 200);
        for ($y = 0; $y < 200; $y++) {
            for ($x = 0; $x < 200; $x++) {
                imagesetpixel($catalog, $x, $y, (((int) ($x / 10)) % 2) === 0 ? $a : $b);
            }
        }

        $screenshot = imagecreatetruecolor(400, 400);
        $chrome = imagecolorallocate($screenshot, 30, 30, 30);
        imagefill($screenshot, 0, 0, $chrome);
        imagecopy($screenshot, $catalog, 100, 100, 0, 0, 200, 200);
        imagedestroy($catalog);

        $relativeDir = 'img/inbox-tests';
        $absoluteDir = public_path($relativeDir);
        if (! is_dir($absoluteDir)) {
            mkdir($absoluteDir, 0775, true);
        }
        $relativePath = $relativeDir.'/crop-suggestion-'.uniqid().'.png';
        $absolutePath = public_path($relativePath);
        imagepng($screenshot, $absolutePath);
        imagedestroy($screenshot);

        $conversation = $this->conversation();
        $message = ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => null,
            'media_url' => '/'.$relativePath,
            'media_mime' => 'image/png',
            'raw_payload' => [
                'message' => [
                    'attachments' => [
                        ['type' => 'image', 'payload' => ['url' => '/'.$relativePath]],
                    ],
                ],
            ],
            'sent_at' => now(),
        ]);

        $response = $this->getJson(route('admin.inbox.crop-suggestion', $message));

        $response->assertOk()
            ->assertJsonPath('suggestion.strategy', 'trim')
            ->assertJsonStructure([
                'suggestion' => ['left', 'top', 'width', 'height', 'strategy'],
            ]);

        $suggestion = $response->json('suggestion');
        $this->assertEqualsWithDelta(0.25, $suggestion['left'], 0.02);
        $this->assertEqualsWithDelta(0.25, $suggestion['top'], 0.02);
        $this->assertEqualsWithDelta(0.5, $suggestion['width'], 0.02);
        $this->assertEqualsWithDelta(0.5, $suggestion['height'], 0.02);

        @unlink($absolutePath);
    }

    #[Test]
    public function screenshot_fallback_matches_use_panel_trim_for_chrome_screenshots(): void
    {
        config(['channels.ai_draft.image_min_bytes' => 100]);

        $hasher = app(ProductImageHashService::class);
        [$catalogBytes, $screenshotBytes] = $this->catalogAndScreenshotBytes();

        $relativeDir = 'img/inbox-tests';
        $absoluteDir = public_path($relativeDir);
        if (! is_dir($absoluteDir)) {
            mkdir($absoluteDir, 0775, true);
        }
        $relativePath = $relativeDir.'/map-fallback-'.uniqid().'.png';
        file_put_contents(public_path($relativePath), $screenshotBytes);

        $product = $this->productWithHash($hasher->hashBinary($catalogBytes));

        $message = ChannelMessage::query()->create([
            'channel_conversation_id' => $this->conversation()->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => null,
            'media_url' => '/'.$relativePath,
            'media_mime' => 'image/png',
            'sent_at' => now(),
        ]);

        $matches = app(ChannelMessageImageMatchService::class)
            ->screenshotFallbackMatches($message);

        $this->assertNotEmpty($matches);
        $this->assertSame($product->id, $matches[0]['product_id']);
        $this->assertGreaterThanOrEqual(ProductImageHashService::MIN_MATCH_PERCENT, $matches[0]['match_percent']);

        @unlink(public_path($relativePath));
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function catalogAndScreenshotBytes(): array
    {
        $catalog = imagecreatetruecolor(200, 200);
        $a = imagecolorallocate($catalog, 200, 40, 60);
        $b = imagecolorallocate($catalog, 40, 90, 200);
        for ($y = 0; $y < 200; $y++) {
            for ($x = 0; $x < 200; $x++) {
                imagesetpixel($catalog, $x, $y, (((int) ($x / 10)) % 2) === 0 ? $a : $b);
            }
        }

        $screenshot = imagecreatetruecolor(400, 400);
        $chrome = imagecolorallocate($screenshot, 30, 30, 30);
        imagefill($screenshot, 0, 0, $chrome);
        imagecopy($screenshot, $catalog, 100, 100, 0, 0, 200, 200);

        ob_start();
        imagepng($catalog);
        $catalogBytes = (string) ob_get_clean();
        imagedestroy($catalog);

        ob_start();
        imagepng($screenshot);
        $screenshotBytes = (string) ob_get_clean();
        imagedestroy($screenshot);

        return [$catalogBytes, $screenshotBytes];
    }
}
