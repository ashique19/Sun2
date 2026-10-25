<?php

namespace Tests\Feature;

use App\Livewire\StorefrontCategory;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StorefrontCategoryInfiniteScrollTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Category, 1: list<Product>}
     */
    private function categoryWithProducts(int $count = 30): array
    {
        $category = Category::query()->create([
            'name' => 'নূপুর',
            'slug' => 'nupur-scroll',
            'headline' => 'নূপুর কালেকশন',
            'is_active' => true,
        ]);

        $products = [];

        for ($i = 1; $i <= $count; $i++) {
            $products[] = Product::query()->create([
                'name' => 'Nupur-'.sprintf('%02d', $i),
                'slug' => 'nupur-scroll-'.$i,
                'sku' => 'NP-S-'.$i,
                'price' => 400 + $i,
                'purchase_price' => 200,
                'stock_quantity' => $i === 2 ? 0 : 5,
                'is_published' => true,
                'display_order' => $i,
                'category_id' => $category->id,
            ]);
        }

        return [$category, $products];
    }

    #[Test]
    public function category_page_shows_first_page_and_load_more_control(): void
    {
        [$category, $products] = $this->categoryWithProducts(30);

        $this->get(route('category.show', $category))
            ->assertOk()
            ->assertSee($products[0]->name, false)
            ->assertSee($products[23]->name, false)
            ->assertDontSee($products[24]->name, false)
            ->assertSee(__('storefront.load_more'), false)
            ->assertSee('data-category-load-more', false)
            ->assertSee('wire:intersect="loadMore"', false)
            ->assertDontSee('?page=2', false);
    }

    #[Test]
    public function load_more_appends_the_next_products(): void
    {
        [$category, $products] = $this->categoryWithProducts(30);

        Livewire::test(StorefrontCategory::class, ['category' => $category])
            ->assertSee($products[23]->name)
            ->assertDontSee($products[24]->name)
            ->assertSet('perPage', StorefrontCategory::PAGE_SIZE)
            ->call('loadMore')
            ->assertSet('perPage', StorefrontCategory::PAGE_SIZE * 2)
            ->assertSee($products[24]->name)
            ->assertSee($products[29]->name)
            ->assertDontSee(__('storefront.load_more'));
    }

    #[Test]
    public function changing_sort_resets_to_the_first_page(): void
    {
        [$category] = $this->categoryWithProducts(30);

        Livewire::test(StorefrontCategory::class, ['category' => $category])
            ->call('loadMore')
            ->assertSet('perPage', StorefrontCategory::PAGE_SIZE * 2)
            ->set('sort', 'price_desc')
            ->assertSet('perPage', StorefrontCategory::PAGE_SIZE)
            ->assertSee(__('storefront.load_more'));
    }

    #[Test]
    public function stock_filter_resets_and_hides_out_of_stock_items(): void
    {
        [$category] = $this->categoryWithProducts(26);

        Livewire::test(StorefrontCategory::class, ['category' => $category])
            ->assertSee('Nupur-02')
            ->call('loadMore')
            ->set('inStockOnly', true)
            ->assertSet('perPage', StorefrontCategory::PAGE_SIZE)
            ->assertDontSee('Nupur-02')
            ->assertSee('Nupur-01');
    }

    #[Test]
    public function load_more_is_a_no_op_when_every_product_is_already_visible(): void
    {
        [$category] = $this->categoryWithProducts(10);

        Livewire::test(StorefrontCategory::class, ['category' => $category])
            ->assertDontSee(__('storefront.load_more'))
            ->call('loadMore')
            ->assertSet('perPage', StorefrontCategory::PAGE_SIZE);
    }
}
