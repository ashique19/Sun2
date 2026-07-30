<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminSocialPosts;
use App\Livewire\Admin\AdminSocialPostsEdit;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\SocialPost;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminSocialPostsManageTest extends TestCase
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

    private function makeProduct(Category $category): Product
    {
        $product = Product::query()->create([
            'category_id' => $category->id,
            'name' => 'Gold Chain',
            'slug' => 'gold-chain',
            'price' => 1200,
            'purchase_price' => 600,
            'stock_quantity' => 4,
            'is_published' => true,
        ]);

        ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => 'img/products/gold.jpg',
            'alt' => null,
            'sort_order' => 0,
            'is_primary' => true,
        ]);

        return $product;
    }

    private function makePost(User $admin, Product $product, array $overrides = []): SocialPost
    {
        $post = SocialPost::query()->create(array_merge([
            'body' => 'Homepage jewelry highlight',
            'image_source' => 'thumb',
            'layout' => 'album',
            'status' => SocialPost::STATUS_PUBLISHED,
            'show_on_homepage' => true,
            'created_by' => $admin->id,
            'thumbnail_path' => 'img/products/gold.jpg',
        ], $overrides));

        $post->products()->attach($product->id, [
            'sort_order' => 0,
            'thumb_snapshot_path' => 'img/products/gold.jpg',
            'priced_snapshot_path' => null,
            'selected_image_path' => 'img/products/gold.jpg',
        ]);

        return $post;
    }

    #[Test]
    public function admin_nav_and_index_list_social_posts(): void
    {
        $admin = $this->adminUser();
        $category = $this->makeCategory();
        $product = $this->makeProduct($category);
        $post = $this->makePost($admin, $product);

        $this->actingAs($admin)
            ->get(route('admin.social-posts'))
            ->assertOk()
            ->assertSee('Social Posts')
            ->assertSee('Homepage jewelry highlight')
            ->assertSee(route('admin.social-posts.edit', $post), false);

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee(route('admin.social-posts'), false)
            ->assertSee('Social Posts');
    }

    #[Test]
    public function can_toggle_homepage_visibility_and_edit_post(): void
    {
        $admin = $this->adminUser();
        $category = $this->makeCategory();
        $product = $this->makeProduct($category);
        $post = $this->makePost($admin, $product);

        Livewire::actingAs($admin)
            ->test(AdminSocialPosts::class)
            ->call('toggleShowOnHomepage', $post->id)
            ->assertHasNoErrors();

        $this->assertFalse((bool) $post->fresh()->show_on_homepage);

        $this->get(route('home'))
            ->assertOk()
            ->assertDontSee('Homepage jewelry highlight');

        Livewire::actingAs($admin)
            ->test(AdminSocialPostsEdit::class, ['socialPost' => $post->fresh()])
            ->set('body', 'Updated social post copy for store')
            ->set('showOnHomepage', true)
            ->set('status', SocialPost::STATUS_PUBLISHED)
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('admin.social-posts.show', $post));

        $this->assertDatabaseHas('social_posts', [
            'id' => $post->id,
            'body' => 'Updated social post copy for store',
            'show_on_homepage' => 1,
        ]);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Recent posts')
            ->assertSee('Updated social post copy');
    }

    #[Test]
    public function can_delete_social_post(): void
    {
        $admin = $this->adminUser();
        $category = $this->makeCategory();
        $product = $this->makeProduct($category);
        $post = $this->makePost($admin, $product, [
            'body' => 'Post marked for deletion',
        ]);

        Livewire::actingAs($admin)
            ->test(AdminSocialPosts::class)
            ->call('delete', $post->id)
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('social_posts', ['id' => $post->id]);
        $this->assertDatabaseMissing('social_post_products', ['social_post_id' => $post->id]);
    }

    #[Test]
    public function hidden_homepage_posts_still_open_on_storefront_when_published(): void
    {
        $admin = $this->adminUser();
        $category = $this->makeCategory();
        $product = $this->makeProduct($category);
        $post = $this->makePost($admin, $product, [
            'body' => 'Published but not on homepage',
            'show_on_homepage' => false,
        ]);

        $this->get(route('home'))
            ->assertOk()
            ->assertDontSee('Published but not on homepage');

        $this->get(route('social-post.show', $post))
            ->assertOk()
            ->assertSee('Published but not on homepage');
    }
}
