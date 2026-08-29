<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StorefrontCategoryCompactLayoutTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function category_page_uses_compact_header_and_hides_product_count(): void
    {
        $category = Category::query()->create([
            'name' => 'নূপুর সমগ্র',
            'slug' => 'nupur-collection',
            'headline' => 'বিভিন্ন ধরনের নূপুরের বিস্তৃত সমাহার',
            'is_active' => true,
        ]);

        Product::query()->create([
            'name' => 'পিতলের নূপুর',
            'slug' => 'pitoler-nupur',
            'sku' => 'NP-1',
            'price' => 500,
            'purchase_price' => 200,
            'stock_quantity' => 5,
            'is_published' => true,
            'display_order' => 1,
            'category_id' => $category->id,
        ]);

        $response = $this->get(route('category.show', $category));

        $response->assertOk();
        $response->assertSee('mx-auto max-w-6xl px-4 py-4', false);
        $response->assertDontSee('mx-auto max-w-6xl px-4 py-8', false);
        $response->assertDontSee(__('storefront.products_count', ['count' => 1]), false);
        $response->assertSee(__('storefront.in_stock_only'), false);
        $response->assertSee(__('storefront.sort_featured'), false);
        $response->assertSee($category->name, false);
        $response->assertSee($category->headline, false);
    }

    #[Test]
    public function mobile_header_search_is_toggleable_and_hidden_by_default(): void
    {
        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertSee('aria-label="'.__('storefront.search').'"', false);
        $response->assertSee('mx-auto max-w-6xl px-3 py-3 space-y-2 sm:hidden', false);
        $response->assertSee('flex min-w-0 flex-1 items-center', false);
        $response->assertSee('h-9 w-9 shrink-0', false);
        $response->assertSee('x-data="{ searchOpen: false }"', false);
        $response->assertSee('x-show="searchOpen"', false);
        $response->assertSee('x-ref="mobileSearch"', false);
    }

    #[Test]
    public function mobile_header_search_opens_when_query_is_present(): void
    {
        $response = $this->get(route('shop', ['q' => 'নূপুর']));

        $response->assertOk();
        $response->assertSee('x-data="{ searchOpen: true }"', false);
        $response->assertSee('value="নূপুর"', false);
    }

    #[Test]
    public function product_detail_page_uses_compact_top_layout(): void
    {
        $category = Category::query()->create([
            'name' => 'Earrings',
            'slug' => 'earrings-compact',
            'is_active' => true,
        ]);

        $product = Product::query()->create([
            'name' => 'Jhumka Compact',
            'slug' => 'jhumka-compact',
            'sku' => 'JH-1',
            'price' => 500,
            'purchase_price' => 200,
            'stock_quantity' => 2,
            'is_published' => true,
            'display_order' => 1,
            'category_id' => $category->id,
        ]);

        $response = $this->get(route('product.show', $product));

        $response->assertOk();
        $response->assertSee('mx-auto max-w-6xl px-4 py-4 pb-24 lg:pb-0', false);
        $response->assertDontSee('mx-auto max-w-6xl px-4 py-8 pb-24 lg:pb-0', false);
        $response->assertSee('mb-2 flex items-center gap-2 md:mb-4 md:block', false);
        $response->assertSee('hidden text-[#1E1E1E] line-clamp-1 sm:inline', false);
        $response->assertSee('hidden text-xs uppercase tracking-wider text-[#7A6114] sm:block', false);
        $response->assertSee('aria-label="'.__('storefront.search').'"', false);
        $response->assertSee('x-show="searchOpen"', false);
    }
}
