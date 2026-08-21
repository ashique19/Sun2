<?php

namespace Tests\Feature;

use App\Livewire\StorefrontProduct;
use App\Models\Category;
use App\Models\Product;
use App\Support\StorefrontProductNavigator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StorefrontProductNeighborNavTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Category, 1: Product, 2: Product, 3: Product}
     */
    private function orderedCategoryProducts(): array
    {
        $category = Category::query()->create([
            'name' => 'Earrings',
            'slug' => 'earrings',
            'is_active' => true,
            'is_homepage' => true,
            'display_order' => 1,
        ]);

        // Catalog order: display_order ASC, id DESC → first, middle, last
        $last = Product::query()->create([
            'name' => 'Last Earring',
            'slug' => 'last-earring',
            'price' => 300,
            'display_order' => 2,
            'is_published' => true,
            'category_id' => $category->id,
        ]);
        $middle = Product::query()->create([
            'name' => 'Middle Earring',
            'slug' => 'middle-earring',
            'price' => 200,
            'display_order' => 1,
            'is_published' => true,
            'category_id' => $category->id,
        ]);
        $first = Product::query()->create([
            'name' => 'First Earring',
            'slug' => 'first-earring',
            'price' => 100,
            'display_order' => 1,
            'is_published' => true,
            'category_id' => $category->id,
        ]);

        $this->assertGreaterThan($middle->id, $first->id);

        return [$category, $first, $middle, $last];
    }

    #[Test]
    public function navigator_follows_published_catalog_order_within_category(): void
    {
        [, $first, $middle, $last] = $this->orderedCategoryProducts();

        Product::query()->create([
            'name' => 'Other Category',
            'slug' => 'other-category-product',
            'price' => 50,
            'display_order' => 0,
            'is_published' => true,
            'category_id' => Category::query()->create([
                'name' => 'Rings',
                'slug' => 'rings',
                'is_active' => true,
                'display_order' => 2,
            ])->id,
        ]);

        Product::query()->create([
            'name' => 'Draft Sibling',
            'slug' => 'draft-sibling',
            'price' => 80,
            'display_order' => 1,
            'is_published' => false,
            'category_id' => $first->category_id,
        ]);

        $this->assertNull(StorefrontProductNavigator::previous($first));
        $this->assertSame($middle->id, StorefrontProductNavigator::next($first)?->id);

        $this->assertSame($first->id, StorefrontProductNavigator::previous($middle)?->id);
        $this->assertSame($last->id, StorefrontProductNavigator::next($middle)?->id);

        $this->assertSame($middle->id, StorefrontProductNavigator::previous($last)?->id);
        $this->assertNull(StorefrontProductNavigator::next($last));
    }

    #[Test]
    public function product_page_links_to_previous_and_next_neighbors(): void
    {
        [, $first, $middle, $last] = $this->orderedCategoryProducts();

        Livewire::test(StorefrontProduct::class, ['product' => $middle])
            ->assertSee(__('storefront.previous_product'))
            ->assertSee(__('storefront.next_product'))
            ->assertSeeHtml('aria-label="'.__('storefront.product_navigation').'"')
            ->assertSeeHtml(route('product.show', $first))
            ->assertSeeHtml(route('product.show', $last));

        $firstPage = $this->get(route('product.show', $first));
        $firstPage->assertOk();
        $firstPage->assertSee(__('storefront.next_product'));
        $firstPage->assertSee('Middle Earring');
        $firstPage->assertDontSee(__('storefront.previous_product'));
        $firstPage->assertSeeHtml(route('product.show', $middle));

        $lastPage = $this->get(route('product.show', $last));
        $lastPage->assertOk();
        $lastPage->assertSee(__('storefront.previous_product'));
        $lastPage->assertSee('Middle Earring');
        $lastPage->assertDontSee(__('storefront.next_product'));
    }

    #[Test]
    public function uncategorized_products_navigate_across_published_catalog(): void
    {
        $alpha = Product::query()->create([
            'name' => 'Alpha',
            'slug' => 'alpha',
            'price' => 100,
            'display_order' => 1,
            'is_published' => true,
        ]);
        $beta = Product::query()->create([
            'name' => 'Beta',
            'slug' => 'beta',
            'price' => 200,
            'display_order' => 2,
            'is_published' => true,
        ]);

        $this->assertSame($beta->id, StorefrontProductNavigator::next($alpha)?->id);
        $this->assertSame($alpha->id, StorefrontProductNavigator::previous($beta)?->id);
    }
}
