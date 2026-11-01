<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminInbox;
use App\Models\Category;
use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductImageMatchMemory;
use App\Models\User;
use App\Services\Admin\ProductImageCatalogIndex;
use App\Services\Admin\ProductImageMatchIndex;
use App\Services\Channels\ChannelMessageImageMatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\ProductPhotoFixtures;
use Tests\TestCase;

class AdminInboxTagProductSearchTest extends TestCase
{
    use ProductPhotoFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['channels.ai_draft.image_min_bytes' => 100]);
    }

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function conversation(string $psid = 'psid-tag-search'): ChannelConversation
    {
        return ChannelConversation::query()->create([
            'channel' => ChannelConversation::CHANNEL_MESSENGER,
            'external_user_id' => $psid,
            'customer_name' => 'Tag Search Customer',
            'last_inbound_at' => now(),
        ]);
    }

    private function makeCategory(string $name): Category
    {
        return Category::query()->create([
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'display_order' => 0,
            'is_homepage' => true,
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function publishedProduct(string $name, array $attributes = []): Product
    {
        return Product::query()->create([
            'name' => $name,
            'slug' => str($name)->slug()->toString().'-'.uniqid(),
            'sku' => 'TPS'.random_int(1000, 9999),
            'price' => 1500,
            'purchase_price' => 700,
            'stock_quantity' => 5,
            'is_published' => true,
            'display_order' => 0,
            ...$attributes,
        ]);
    }

    private function photoMessage(ChannelConversation $conversation, string $url): ChannelMessage
    {
        return ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'media_url' => $url,
            'media_mime' => 'image/jpeg',
            'sent_at' => now(),
        ]);
    }

    #[Test]
    public function tag_modal_includes_order_entry_search_filters(): void
    {
        $this->actingAs($this->adminUser());
        $rings = $this->makeCategory('Rings');
        $conversation = $this->conversation();
        $message = $this->photoMessage($conversation, 'https://example.test/tag-search.jpg');

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->call('openTagProductOnImage', $message->id)
            ->assertSet('mappingMode', 'tag')
            ->assertSee('Tag product on photo')
            ->assertSee('All categories')
            ->assertSee('Rings')
            ->assertSee('Any stock')
            ->assertSee('In stock')
            ->assertSee('Out of stock')
            ->assertSeeHtml('aria-label="Minimum price"')
            ->assertSeeHtml('aria-label="Maximum price"')
            ->assertSeeHtml('wire:model.live="mappingProductCategory"')
            ->assertSeeHtml('wire:model.live="mappingProductStock"')
            ->assertSeeHtml('placeholder="Min price"')
            ->assertSeeHtml('placeholder="Max price"')
            ->assertSee('Type at least 2 characters, pick a category, filter by stock, or set a price range.')
            ->assertSet('mappingProductCategory', '')
            ->assertSet('mappingProductStock', '')
            ->assertSet('mappingProductPriceMin', '')
            ->assertSet('mappingProductPriceMax', '');

        $this->assertSame($rings->id, Category::query()->where('name', 'Rings')->value('id'));
    }

    #[Test]
    public function tag_search_filters_by_category_price_range_and_stock(): void
    {
        $this->actingAs($this->adminUser());
        $rings = $this->makeCategory('Rings');
        $sets = $this->makeCategory('Sets');

        $inRange = $this->publishedProduct('Gold Ring In Range', [
            'category_id' => $rings->id,
            'price' => 1800,
            'stock_quantity' => 4,
        ]);
        $this->publishedProduct('Gold Ring Expensive', [
            'category_id' => $rings->id,
            'price' => 9000,
            'stock_quantity' => 4,
        ]);
        $this->publishedProduct('Gold Ring Out of Stock', [
            'category_id' => $rings->id,
            'price' => 1700,
            'stock_quantity' => 0,
        ]);
        $this->publishedProduct('Gold Set Necklace', [
            'category_id' => $sets->id,
            'price' => 1900,
            'stock_quantity' => 6,
        ]);

        $conversation = $this->conversation();
        $message = $this->photoMessage($conversation, 'https://example.test/tag-filters.jpg');

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->call('openTagProductOnImage', $message->id)
            ->set('mappingProductSearch', 'Gold')
            ->assertSee('Gold Ring In Range')
            ->assertSee('Gold Ring Expensive')
            ->assertSee('Gold Ring Out of Stock')
            ->assertSee('Gold Set Necklace')
            ->set('mappingProductCategory', (string) $rings->id)
            ->assertSee('Gold Ring In Range')
            ->assertSee('Gold Ring Expensive')
            ->assertSee('Gold Ring Out of Stock')
            ->assertDontSee('Gold Set Necklace')
            ->set('mappingProductStock', 'in')
            ->assertSee('Gold Ring In Range')
            ->assertSee('Gold Ring Expensive')
            ->assertDontSee('Gold Ring Out of Stock')
            ->assertDontSee('Gold Set Necklace')
            ->set('mappingProductPriceMin', '1500')
            ->set('mappingProductPriceMax', '2000')
            ->assertSee('Gold Ring In Range')
            ->assertDontSee('Gold Ring Expensive')
            ->assertDontSee('Gold Ring Out of Stock')
            ->assertDontSee('Gold Set Necklace')
            ->assertSet('mappingProductSuggestions.0.id', $inRange->id);
    }

    #[Test]
    public function category_or_price_range_alone_is_enough_to_search(): void
    {
        $this->actingAs($this->adminUser());
        $rings = $this->makeCategory('Rings');
        $this->publishedProduct('Solo Category Ring', [
            'category_id' => $rings->id,
            'price' => 1250,
        ]);
        $this->publishedProduct('Other Category Pendant', [
            'category_id' => $this->makeCategory('Pendants')->id,
            'price' => 1250,
        ]);
        $this->publishedProduct('Budget Ring', [
            'category_id' => $rings->id,
            'price' => 400,
        ]);

        $conversation = $this->conversation();
        $message = $this->photoMessage($conversation, 'https://example.test/tag-filter-only.jpg');

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->call('openTagProductOnImage', $message->id)
            ->set('mappingProductCategory', (string) $rings->id)
            ->assertSee('Solo Category Ring')
            ->assertSee('Budget Ring')
            ->assertDontSee('Other Category Pendant')
            ->set('mappingProductCategory', '')
            ->set('mappingProductPriceMin', '1000')
            ->set('mappingProductPriceMax', '1500')
            ->assertSee('Solo Category Ring')
            ->assertSee('Other Category Pendant')
            ->assertDontSee('Budget Ring');
    }

    #[Test]
    public function tagging_saves_the_photo_on_product_image_meta_for_future_matches(): void
    {
        $product = $this->catalogProduct('Studio Shot Necklace', $this->productPhotoBytes(82));
        $customerPhoto = $this->productPhotoBytes(91);
        $firstUrl = 'https://cdn.example.test/customer-tag.jpg';
        $secondUrl = 'https://cdn.example.test/customer-tag-again.jpg';
        Http::fake([
            $firstUrl => Http::response($customerPhoto, 200, ['Content-Type' => 'image/jpeg']),
            $secondUrl => Http::response($customerPhoto, 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $this->actingAs($this->adminUser());
        $conversation = $this->conversation();
        $first = $this->photoMessage($conversation, $firstUrl);

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->call('openTagProductOnImage', $first->id)
            ->call('tagMatchedProduct', $product->id)
            ->assertHasNoErrors();

        $this->assertSame($product->id, $first->fresh()->matched_product_id);

        $tagged = ProductImage::query()
            ->where('product_id', $product->id)
            ->where('is_admin_only', true)
            ->where('alt', ChannelMessageImageMatchService::inboxTagAlt($first))
            ->first();

        $this->assertNotNull($tagged);
        $this->assertNotNull($tagged->perceptual_hash);
        $this->assertNotNull($tagged->match_signature);
        $this->assertIsArray($tagged->perceptual_hashes);
        $storedHashes = array_values(array_filter([
            $tagged->perceptual_hash,
            ...array_column($tagged->perceptual_hashes, 'hash'),
        ]));
        $this->assertNotEmpty($storedHashes);
        $this->assertFileExists(public_path(ltrim((string) $tagged->path, '/')));

        ProductImageMatchMemory::query()->delete();
        ProductImageMatchIndex::forget();
        ProductImageCatalogIndex::forgetLoaded();
        ProductImageCatalogIndex::bump();

        $second = $this->photoMessage($this->conversation('psid-tag-search-2'), $secondUrl);
        $match = app(ChannelMessageImageMatchService::class)->bestAutoMatch($second);

        $this->assertSame($product->id, $match['product_id'] ?? null);
        $this->assertContains($second->fresh()->image_match_meta['source'] ?? null, ['visual', 'staff_memory']);
    }

    #[Test]
    public function clearing_a_tag_removes_the_saved_catalog_image(): void
    {
        $product = $this->catalogProduct('Clearable Necklace', $this->productPhotoBytes(92));
        $customerPhoto = $this->productPhotoBytes(93);
        $url = 'https://cdn.example.test/clear-tag.jpg';
        Http::fake([$url => Http::response($customerPhoto, 200, ['Content-Type' => 'image/jpeg'])]);

        $this->actingAs($this->adminUser());
        $conversation = $this->conversation();
        $message = $this->photoMessage($conversation, $url);

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->call('openTagProductOnImage', $message->id)
            ->call('tagMatchedProduct', $product->id)
            ->assertHasNoErrors();

        $this->assertSame(1, ProductImage::query()
            ->where('product_id', $product->id)
            ->where('is_admin_only', true)
            ->count());

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->call('clearMatchedProduct', $message->id)
            ->assertHasNoErrors();

        $this->assertSame(0, ProductImage::query()
            ->where('product_id', $product->id)
            ->where('is_admin_only', true)
            ->count());
        $this->assertNull($message->fresh()->matched_product_id);
    }
}
