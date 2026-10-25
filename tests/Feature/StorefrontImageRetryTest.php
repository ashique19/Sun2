<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StorefrontImageRetryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function category_listing_images_expose_retry_monitor_hooks(): void
    {
        $category = Category::query()->create([
            'name' => 'নূপুর',
            'slug' => 'nupur-retry',
            'is_active' => true,
        ]);

        $product = Product::query()->create([
            'name' => 'Retry Nupur',
            'slug' => 'retry-nupur',
            'sku' => 'NP-R-1',
            'price' => 500,
            'purchase_price' => 200,
            'stock_quantity' => 3,
            'is_published' => true,
            'display_order' => 1,
            'category_id' => $category->id,
        ]);

        ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => 'img/thumb/retry-nupur.jpg',
            'is_primary' => true,
            'sort_order' => 1,
        ]);

        $this->get(route('category.show', $category))
            ->assertOk()
            ->assertSee('data-image-retry-wrap', false)
            ->assertSee('data-image-retry', false)
            ->assertSee('data-image-src', false)
            ->assertSee('data-image-retry-btn', false)
            ->assertSee('data-image-loading-spinner', false)
            ->assertSee('data-image-loading', false)
            ->assertSee(__('storefront.image_retry'), false);
    }

    #[Test]
    public function product_page_hero_image_can_be_retried(): void
    {
        $category = Category::query()->create([
            'name' => 'Earrings',
            'slug' => 'earrings-retry',
            'is_active' => true,
        ]);

        $product = Product::query()->create([
            'name' => 'Retry Jhumka',
            'slug' => 'retry-jhumka',
            'sku' => 'JH-R-1',
            'price' => 700,
            'purchase_price' => 250,
            'stock_quantity' => 2,
            'is_published' => true,
            'display_order' => 1,
            'category_id' => $category->id,
        ]);

        ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => 'img/thumb/retry-jhumka.jpg',
            'is_primary' => true,
            'sort_order' => 1,
        ]);

        $this->get(route('product.show', $product))
            ->assertOk()
            ->assertSee('data-image-retry', false)
            ->assertSee(__('storefront.image_retry'), false);
    }
}
