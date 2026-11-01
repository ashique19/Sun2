<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminProducts;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Support\StorefrontAssets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminProductsThumbnailGalleryTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function product(string $name = 'Gold Jhumka', string $slug = 'gold-jhumka'): Product
    {
        return Product::query()->create([
            'name' => $name,
            'slug' => $slug,
            'sku' => 'GJ-1',
            'price' => 1200,
            'is_published' => true,
            'display_order' => 1,
        ]);
    }

    #[Test]
    public function products_list_thumbnail_opens_gallery_with_details_link(): void
    {
        $this->actingAs($this->adminUser());

        $product = $this->product();

        ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => 'img/thumb/jhumka-side_md.jpg',
            'alt' => 'Side',
            'is_primary' => false,
            'is_admin_only' => false,
            'sort_order' => 2,
        ]);

        ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => 'img/thumb/jhumka-front_md.jpg',
            'alt' => 'Front',
            'is_primary' => true,
            'is_admin_only' => false,
            'sort_order' => 1,
        ]);

        ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => 'img/thumb/jhumka-admin_md.jpg',
            'alt' => 'Admin only',
            'is_primary' => false,
            'is_admin_only' => true,
            'sort_order' => 0,
        ]);

        $front = StorefrontAssets::largestAvailableUrl('img/thumb/jhumka-front_md.jpg');
        $side = StorefrontAssets::largestAvailableUrl('img/thumb/jhumka-side_md.jpg');
        $adminOnly = StorefrontAssets::largestAvailableUrl('img/thumb/jhumka-admin_md.jpg');
        $detailsUrl = route('admin.products.show', $product);

        Livewire::test(AdminProducts::class)
            ->assertSeeHtml('data-product-gallery-thumb')
            ->assertSeeHtml('wire:click="openProductGallery('.$product->id.')"')
            ->assertSeeHtml('cursor-zoom-in')
            ->assertSee(StorefrontAssets::url('img/thumb/jhumka-front_md.jpg'), false)
            ->call('openProductGallery', $product->id)
            ->assertDispatched('open-product-image', function (string $event, array $params) use ($front, $side, $adminOnly, $detailsUrl, $product) {
                $this->assertSame('open-product-image', $event);
                $this->assertSame($front, $params['imageUrl'] ?? null);
                $this->assertSame([$front, $side], $params['imageUrls'] ?? null);
                $this->assertSame($product->name, $params['productName'] ?? null);
                $this->assertSame($detailsUrl, $params['productUrl'] ?? null);
                $this->assertSame('View product details', $params['linkLabel'] ?? null);
                $this->assertSame('', $params['linkTarget'] ?? null);
                $this->assertNotContains($adminOnly, $params['imageUrls'] ?? []);

                return true;
            });
    }

    #[Test]
    public function products_list_without_images_has_no_gallery_thumb(): void
    {
        $this->actingAs($this->adminUser());

        $product = $this->product('No Photo', 'no-photo');

        Livewire::test(AdminProducts::class)
            ->assertSee('No Photo')
            ->assertDontSeeHtml('data-product-gallery-thumb')
            ->assertDontSeeHtml('openProductGallery('.$product->id.')')
            ->call('openProductGallery', $product->id)
            ->assertNotDispatched('open-product-image');
    }

    #[Test]
    public function products_page_mounts_shared_image_slider_modal(): void
    {
        $this->actingAs($this->adminUser());

        $product = $this->product();
        ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => 'img/thumb/jhumka-front_md.jpg',
            'is_primary' => true,
            'is_admin_only' => false,
            'sort_order' => 0,
        ]);

        $this->get(route('admin.products'))
            ->assertOk()
            ->assertSee('data-product-gallery-thumb', false)
            ->assertSee('x-on:open-product-image.window', false)
            ->assertSee('imageUrls', false)
            ->assertSee('data-product-image-slider', false)
            ->assertSee('View product on store', false);
    }
}
