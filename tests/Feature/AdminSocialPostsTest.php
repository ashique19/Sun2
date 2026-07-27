<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminSocialPostsCreate;
use App\Livewire\Admin\AdminSocialPostsShow;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\SocialPost;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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
            ->set('imageSource', 'thumb')
            ->set('layout', 'album')
            ->call('publish')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('social_posts', [
            'id' => SocialPost::query()->latest('id')->value('id'),
            'status' => SocialPost::STATUS_PUBLISHED,
        ]);

        $this->assertDatabaseHas('social_post_products', [
            'product_id' => $product->id,
        ]);

        $this->assertSame(0, \App\Models\SocialPostPublication::query()->count());
    }

    #[Test]
    public function publish_creates_publication_rows_for_facebook_and_instagram(): void
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
            'https://graph.facebook.com/v25.0/fb-page-1/feed*' => Http::response([
                'id' => 'fbpub-1',
                'permalink_url' => 'https://www.facebook.com/fbpub-1',
            ], 200),
            'https://graph.facebook.com/v25.0/fb-page-1*' => Http::response([
                'instagram_business_account' => ['id' => 'ig-1'],
            ], 200),
            'https://graph.facebook.com/v25.0/ig-1/media*' => Http::response([
                'id' => 'creation-1',
            ], 200),
            'https://graph.facebook.com/v25.0/ig-1/media_publish*' => Http::response([
                'id' => 'igpub-1',
                'permalink_url' => 'https://www.instagram.com/p/igpub-1',
            ], 200),
        ]);

        Livewire::actingAs($admin)
            ->test(AdminSocialPostsCreate::class, ['products' => (string) $product->id])
            ->set('body', 'New season jewelry')
            ->set('imageSource', 'thumb')
            ->set('layout', 'album')
            ->call('publish')
            ->assertHasNoErrors();

        $this->assertSame(2, \App\Models\SocialPostPublication::query()->count());
        $this->assertDatabaseHas('social_post_publications', [
            'channel' => \App\Models\SocialPostPublication::CHANNEL_FACEBOOK,
            'status' => \App\Models\SocialPostPublication::STATUS_SUCCESS,
        ]);
        $this->assertDatabaseHas('social_post_publications', [
            'channel' => \App\Models\SocialPostPublication::CHANNEL_INSTAGRAM,
            'status' => \App\Models\SocialPostPublication::STATUS_SUCCESS,
        ]);
    }

    #[Test]
    public function homepage_lists_published_latest_posts(): void
    {
        $admin = $this->adminUser();
        $category = $this->makeCategory();
        $product = $this->makeProduct($category, 'p3', 'img/products/p3.jpg', 'Ring');

        $post = SocialPost::query()->create([
            'body' => 'This is a test latest post',
            'image_source' => 'thumb',
            'layout' => 'album',
            'status' => SocialPost::STATUS_PUBLISHED,
            'created_by' => $admin->id,
            'thumbnail_path' => 'img/products/p3.jpg',
        ]);

        $post->products()->attach($product->id, [
            'sort_order' => 0,
            'thumb_snapshot_path' => 'img/products/p3.jpg',
            'priced_snapshot_path' => null,
        ]);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Latest posts')
            ->assertSee('test latest post');
    }

    #[Test]
    public function republish_creates_additional_publication_attempts(): void
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
            'https://graph.facebook.com/v25.0/fb-page-2/feed*' => Http::response([
                'id' => 'fbpub-2',
                'permalink_url' => 'https://www.facebook.com/fbpub-2',
            ], 200),
            'https://graph.facebook.com/v25.0/fb-page-2*' => Http::response([
                'instagram_business_account' => ['id' => 'ig-2'],
            ], 200),
            'https://graph.facebook.com/v25.0/ig-2/media*' => Http::response([
                'id' => 'creation-2',
            ], 200),
            'https://graph.facebook.com/v25.0/ig-2/media_publish*' => Http::response([
                'id' => 'igpub-2',
                'permalink_url' => 'https://www.instagram.com/p/igpub-2',
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
        ]);

        Livewire::actingAs($admin)
            ->test(AdminSocialPostsShow::class, ['socialPost' => $post])
            ->call('republish')
            ->assertHasNoErrors();

        $this->assertSame(2, \App\Models\SocialPostPublication::query()->count());

        Livewire::actingAs($admin)
            ->test(AdminSocialPostsShow::class, ['socialPost' => $post])
            ->call('republish')
            ->assertHasNoErrors();

        $this->assertSame(4, \App\Models\SocialPostPublication::query()->count());
    }
}

