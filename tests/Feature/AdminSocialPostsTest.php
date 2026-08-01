<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminSocialPostsCreate;
use App\Livewire\Admin\AdminSocialPostsShow;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\SocialPost;
use App\Models\SocialPostPublication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminSocialPostsTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function makeCategory(): Category
    {
        return Category::query()->create([
            'name' => 'Necklaces',
            'slug' => 'necklaces',
            'display_order' => 0,
            'is_homepage' => true,
            'is_active' => true,
        ]);
    }

    private function makeProduct(Category $category, string $slug, string $path, string $name = 'Test Product'): Product
    {
        $product = Product::query()->create([
            'category_id' => $category->id,
            'name' => $name,
            'slug' => $slug,
            'price' => 1000,
            'purchase_price' => 500,
            'stock_quantity' => 10,
            'is_published' => true,
        ]);

        ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => $path,
            'alt' => null,
            'sort_order' => 0,
            'is_primary' => true,
        ]);

        return $product;
    }

    #[Test]
    public function persist_post_without_configured_channels(): void
    {
        $admin = $this->adminUser();
        $category = $this->makeCategory();
        $product = $this->makeProduct($category, 'p1', 'img/products/p1.jpg');

        config([
            'facebook.messenger.page_access_token' => '',
            'facebook.messenger.page_id' => '',
        ]);

        Livewire::actingAs($admin)
            ->test(AdminSocialPostsCreate::class, ['products' => (string) $product->id])
            ->set('body', 'Hello from Sun2')
            ->set('layout', 'album')
            ->call('publish')
            ->assertHasNoErrors()
            ->assertSet('phase', 'done')
            ->assertDontSee('Instagram');

        $postId = SocialPost::query()->latest('id')->value('id');

        $this->assertDatabaseHas('social_posts', [
            'id' => $postId,
            'status' => SocialPost::STATUS_PUBLISHED,
        ]);

        $this->assertDatabaseHas('social_post_products', [
            'product_id' => $product->id,
            'selected_image_path' => 'img/products/p1.jpg',
        ]);

        $this->assertSame(1, SocialPostPublication::query()->count());
        $this->assertDatabaseHas('social_post_publications', [
            'channel' => SocialPostPublication::CHANNEL_FACEBOOK,
            'status' => SocialPostPublication::STATUS_FAILED,
        ]);
        $this->assertDatabaseMissing('social_post_publications', [
            'channel' => SocialPostPublication::CHANNEL_INSTAGRAM,
        ]);
    }

    #[Test]
    public function create_page_is_facebook_only_and_shows_preview(): void
    {
        $admin = $this->adminUser();
        $category = $this->makeCategory();
        $product = $this->makeProduct($category, 'p-preview', 'img/products/preview.jpg');

        Livewire::actingAs($admin)
            ->test(AdminSocialPostsCreate::class, ['products' => (string) $product->id])
            ->set('body', 'Preview copy for Facebook')
            ->assertSee('Facebook preview')
            ->assertSee('Preview copy for Facebook')
            ->assertSee('Make Facebook Post')
            ->assertSee('Include product links in caption')
            ->assertSee(route('product.show', $product))
            ->assertDontSeeHtml('wire:model.live="postToInstagram"')
            ->assertDontSee('Post to');
    }

    #[Test]
    public function caption_includes_custom_text_intro_and_selected_product_urls(): void
    {
        $admin = $this->adminUser();
        $category = $this->makeCategory();
        $first = $this->makeProduct($category, 'gold-necklace', 'img/products/gold.jpg', 'Gold Necklace');
        $second = $this->makeProduct($category, 'silver-ring', 'img/products/silver.jpg', 'Silver Ring');

        Livewire::actingAs($admin)
            ->test(AdminSocialPostsCreate::class, ['products' => $first->id.','.$second->id])
            ->set('body', 'New arrivals this week')
            ->set('includeProductUrls', true)
            ->set('productLinkIntro', 'Order / details:')
            ->assertSee('New arrivals this week')
            ->assertSee('Order / details:')
            ->assertSee('Gold Necklace')
            ->assertSee(route('product.show', $first))
            ->assertSee(route('product.show', $second))
            ->call('createPost')
            ->assertHasNoErrors()
            ->assertSet('phase', 'publishing');

        $post = SocialPost::query()->latest('id')->first();
        $this->assertNotNull($post);

        $expected = "New arrivals this week\n\nOrder / details:\n\nGold Necklace\n"
            .route('product.show', $first)
            ."\n\nSilver Ring\n"
            .route('product.show', $second);

        $this->assertSame($expected, $post->body);
    }

    #[Test]
    public function caption_can_omit_product_urls_when_disabled(): void
    {
        $admin = $this->adminUser();
        $category = $this->makeCategory();
        $product = $this->makeProduct($category, 'no-links', 'img/products/no-links.jpg', 'No Links Product');

        Livewire::actingAs($admin)
            ->test(AdminSocialPostsCreate::class, ['products' => (string) $product->id])
            ->set('body', 'Caption only custom text')
            ->set('includeProductUrls', false)
            ->call('createPost')
            ->assertHasNoErrors();

        $post = SocialPost::query()->latest('id')->first();
        $this->assertNotNull($post);
        $this->assertSame('Caption only custom text', $post->body);
        $this->assertStringNotContainsString(route('product.show', $product), $post->body);
    }

    #[Test]
    public function product_urls_follow_reordered_selection(): void
    {
        $admin = $this->adminUser();
        $category = $this->makeCategory();
        $first = $this->makeProduct($category, 'first-item', 'img/products/first.jpg', 'First Item');
        $second = $this->makeProduct($category, 'second-item', 'img/products/second.jpg', 'Second Item');

        Livewire::actingAs($admin)
            ->test(AdminSocialPostsCreate::class, ['products' => $first->id.','.$second->id])
            ->call('reorderProducts', [$second->id, $first->id])
            ->set('body', 'Reorder check')
            ->set('includeProductUrls', true)
            ->call('createPost')
            ->assertHasNoErrors();

        $post = SocialPost::query()->latest('id')->first();
        $this->assertNotNull($post);

        $secondPos = strpos($post->body, route('product.show', $second));
        $firstPos = strpos($post->body, route('product.show', $first));

        $this->assertNotFalse($secondPos);
        $this->assertNotFalse($firstPos);
        $this->assertLessThan($firstPos, $secondPos);
    }

    #[Test]
    public function products_can_be_reordered_and_image_selected(): void
    {
        $admin = $this->adminUser();
        $category = $this->makeCategory();
        $first = $this->makeProduct($category, 'p-a', 'img/products/a.jpg', 'Product A');
        $second = $this->makeProduct($category, 'p-b', 'img/products/b.jpg', 'Product B');

        ProductImage::query()->create([
            'product_id' => $first->id,
            'path' => 'img/products/a-alt.jpg',
            'alt' => null,
            'sort_order' => 1,
            'is_primary' => false,
        ]);

        if (Schema::hasColumn('products', 'priced_image_path')) {
            $first->update(['priced_image_path' => 'img/products/a-priced.jpg']);
        }

        Livewire::actingAs($admin)
            ->test(AdminSocialPostsCreate::class, ['products' => $first->id.','.$second->id])
            ->call('reorderProducts', [$second->id, $first->id])
            ->assertSet('products', $second->id.','.$first->id)
            ->call('selectProductImage', $first->id, 'img/products/a-alt.jpg')
            ->assertSet('selectedImagePaths.'.(string) $first->id, 'img/products/a-alt.jpg')
            ->set('body', 'Ordered album post')
            ->call('createPost')
            ->assertHasNoErrors()
            ->assertSet('phase', 'publishing');

        $post = SocialPost::query()->latest('id')->first();
        $this->assertNotNull($post);

        $ordered = $post->products()->orderBy('social_post_products.sort_order')->get();
        $this->assertSame([$second->id, $first->id], $ordered->pluck('id')->all());
        $this->assertSame('img/products/a-alt.jpg', $ordered->last()->pivot->selected_image_path);
        $this->assertSame('img/products/b.jpg', $ordered->first()->pivot->selected_image_path);
    }

    #[Test]
    public function publish_only_facebook_channel(): void
    {
        $admin = $this->adminUser();
        $category = $this->makeCategory();
        $product = $this->makeProduct($category, 'p1c', 'img/products/p1c.jpg');

        config([
            'facebook.messenger.page_access_token' => 'page-token',
            'facebook.messenger.page_id' => 'fb-page-only',
            'facebook.graph_version' => 'v25.0',
        ]);

        Http::fake([
            'https://graph.facebook.com/v25.0/fb-page-only/photos*' => Http::response([
                'id' => 'photo-only',
                'post_id' => 'fbpub-only',
            ], 200),
            'https://graph.facebook.com/v25.0/fbpub-only*' => Http::response([
                'permalink_url' => 'https://www.facebook.com/fbpub-only',
            ], 200),
        ]);

        Livewire::actingAs($admin)
            ->test(AdminSocialPostsCreate::class, ['products' => (string) $product->id])
            ->set('body', 'Facebook only post')
            ->call('publish')
            ->assertHasNoErrors()
            ->assertSet('phase', 'done')
            ->assertSet('channelProgress.facebook.status', 'success');

        $this->assertSame(1, SocialPostPublication::query()->count());
        $this->assertDatabaseHas('social_post_publications', [
            'channel' => SocialPostPublication::CHANNEL_FACEBOOK,
            'status' => SocialPostPublication::STATUS_SUCCESS,
        ]);
        $this->assertDatabaseMissing('social_post_publications', [
            'channel' => SocialPostPublication::CHANNEL_INSTAGRAM,
        ]);
    }

    #[Test]
    public function progressive_publish_shows_posting_then_success_for_facebook(): void
    {
        $admin = $this->adminUser();
        $category = $this->makeCategory();
        $product = $this->makeProduct($category, 'p1d', 'img/products/p1d.jpg');

        config([
            'facebook.messenger.page_access_token' => 'page-token',
            'facebook.messenger.page_id' => 'fb-page-prog',
            'facebook.graph_version' => 'v25.0',
        ]);

        Http::fake([
            'https://graph.facebook.com/v25.0/fb-page-prog/photos*' => Http::response([
                'id' => 'photo-prog',
                'post_id' => 'fbpub-prog',
            ], 200),
            'https://graph.facebook.com/v25.0/fbpub-prog*' => Http::response([
                'permalink_url' => 'https://www.facebook.com/fbpub-prog',
            ], 200),
        ]);

        Livewire::actingAs($admin)
            ->test(AdminSocialPostsCreate::class, ['products' => (string) $product->id])
            ->set('body', 'Progressive publish')
            ->call('createPost')
            ->assertSet('phase', 'publishing')
            ->assertSet('channelProgress.facebook.status', 'waiting')
            ->assertSet('channelProgress.instagram', null)
            ->call('markChannelPosting', 'facebook')
            ->assertSet('channelProgress.facebook.status', 'posting')
            ->call('publishSelectedChannel', 'facebook')
            ->assertSet('channelProgress.facebook.status', 'success')
            ->assertSet('phase', 'done');

        $this->assertSame(1, SocialPostPublication::query()->count());
    }

    #[Test]
    public function finished_channel_publish_is_not_repeated_when_progress_ui_retries(): void
    {
        $admin = $this->adminUser();
        $category = $this->makeCategory();
        $product = $this->makeProduct($category, 'p1e', 'img/products/p1e.jpg');

        config([
            'facebook.messenger.page_access_token' => 'page-token',
            'facebook.messenger.page_id' => 'fb-page-once',
            'facebook.graph_version' => 'v25.0',
        ]);

        Http::fake([
            'https://graph.facebook.com/v25.0/fb-page-once/photos*' => Http::response([
                'id' => 'photo-once',
                'post_id' => 'fbpub-once',
            ], 200),
            'https://graph.facebook.com/v25.0/fbpub-once*' => Http::response([
                'permalink_url' => 'https://www.facebook.com/fbpub-once',
            ], 200),
        ]);

        Livewire::actingAs($admin)
            ->test(AdminSocialPostsCreate::class, ['products' => (string) $product->id])
            ->set('body', 'Publish once only')
            ->call('createPost')
            ->assertSeeHtml('x-init="publishWaitingChannels()"')
            ->call('publishSelectedChannel', 'facebook')
            ->assertSet('channelProgress.facebook.status', 'success')
            ->assertSet('phase', 'done')
            ->call('publishSelectedChannel', 'facebook')
            ->assertSet('channelProgress.facebook.status', 'success');

        $this->assertSame(1, SocialPostPublication::query()->count());
        $this->assertDatabaseHas('social_post_publications', [
            'channel' => SocialPostPublication::CHANNEL_FACEBOOK,
            'status' => SocialPostPublication::STATUS_SUCCESS,
        ]);
    }

    #[Test]
    public function publish_creates_publication_row_for_facebook(): void
    {
        $admin = $this->adminUser();
        $category = $this->makeCategory();
        $product = $this->makeProduct($category, 'p2', 'img/products/p2.jpg');

        config([
            'facebook.messenger.page_access_token' => 'page-token',
            'facebook.messenger.page_id' => 'fb-page-1',
            'facebook.graph_version' => 'v25.0',
        ]);

        Http::fake([
            'https://graph.facebook.com/v25.0/fb-page-1/photos*' => Http::response([
                'id' => 'photo-1',
                'post_id' => 'fbpub-1',
            ], 200),
            'https://graph.facebook.com/v25.0/fbpub-1*' => Http::response([
                'permalink_url' => 'https://www.facebook.com/fbpub-1',
            ], 200),
        ]);

        Livewire::actingAs($admin)
            ->test(AdminSocialPostsCreate::class, ['products' => (string) $product->id])
            ->set('body', 'New season jewelry')
            ->set('layout', 'album')
            ->call('publish')
            ->assertHasNoErrors()
            ->assertSet('phase', 'done');

        $this->assertSame(1, SocialPostPublication::query()->count());
        $this->assertDatabaseHas('social_post_publications', [
            'channel' => SocialPostPublication::CHANNEL_FACEBOOK,
            'status' => SocialPostPublication::STATUS_SUCCESS,
        ]);
    }

    #[Test]
    public function homepage_lists_published_recent_posts(): void
    {
        $admin = $this->adminUser();
        $category = $this->makeCategory();
        $product = $this->makeProduct($category, 'p3', 'img/products/p3.jpg', 'Ring');

        $post = SocialPost::query()->create([
            'body' => 'This is a test recent post',
            'image_source' => 'thumb',
            'layout' => 'album',
            'status' => SocialPost::STATUS_PUBLISHED,
            'show_on_homepage' => true,
            'created_by' => $admin->id,
            'thumbnail_path' => 'img/products/p3.jpg',
        ]);

        $post->products()->attach($product->id, [
            'sort_order' => 0,
            'thumb_snapshot_path' => 'img/products/p3.jpg',
            'priced_snapshot_path' => null,
            'selected_image_path' => 'img/products/p3.jpg',
        ]);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Recent posts')
            ->assertSee('test recent post')
            ->assertSee('id="recent-posts"', false);
    }

    #[Test]
    public function republish_creates_additional_facebook_publication_attempts(): void
    {
        $admin = $this->adminUser();
        $category = $this->makeCategory();
        $product = $this->makeProduct($category, 'p4', 'img/products/p4.jpg', 'Necklace');

        config([
            'facebook.messenger.page_access_token' => 'page-token',
            'facebook.messenger.page_id' => 'fb-page-2',
            'facebook.graph_version' => 'v25.0',
        ]);

        Http::fake([
            'https://graph.facebook.com/v25.0/fb-page-2/photos*' => Http::response([
                'id' => 'photo-2',
                'post_id' => 'fbpub-2',
            ], 200),
            'https://graph.facebook.com/v25.0/fbpub-2*' => Http::response([
                'permalink_url' => 'https://www.facebook.com/fbpub-2',
            ], 200),
        ]);

        $post = SocialPost::query()->create([
            'body' => 'Republish demo',
            'image_source' => 'thumb',
            'layout' => 'album',
            'status' => SocialPost::STATUS_PUBLISHED,
            'created_by' => $admin->id,
            'thumbnail_path' => 'img/products/p4.jpg',
        ]);
        $post->products()->attach($product->id, [
            'sort_order' => 0,
            'thumb_snapshot_path' => 'img/products/p4.jpg',
            'priced_snapshot_path' => null,
            'selected_image_path' => 'img/products/p4.jpg',
        ]);

        Livewire::actingAs($admin)
            ->test(AdminSocialPostsShow::class, ['socialPost' => $post])
            ->assertSee('Re-publish to Facebook')
            ->assertDontSeeHtml('wire:model.live="postToInstagram"')
            ->call('republish')
            ->assertHasNoErrors()
            ->assertSet('republishPhase', 'done');

        $this->assertSame(1, SocialPostPublication::query()->count());

        Livewire::actingAs($admin)
            ->test(AdminSocialPostsShow::class, ['socialPost' => $post])
            ->call('republish')
            ->assertHasNoErrors()
            ->assertSet('republishPhase', 'done');

        $this->assertSame(2, SocialPostPublication::query()->count());
        $this->assertSame(0, SocialPostPublication::query()->where('channel', SocialPostPublication::CHANNEL_INSTAGRAM)->count());
    }
}
