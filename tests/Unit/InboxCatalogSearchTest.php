<?php

namespace Tests\Unit;

use App\Models\Category;
use App\Models\Product;
use App\Services\Admin\InboxCatalogSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InboxCatalogSearchTest extends TestCase
{
    use RefreshDatabase;

    private InboxCatalogSearch $search;

    protected function setUp(): void
    {
        parent::setUp();

        $this->search = app(InboxCatalogSearch::class);
    }

    #[Test]
    public function mapping_suggestions_filter_by_term_category_stock_and_price(): void
    {
        $rings = $this->category('Rings');
        $sets = $this->category('Sets');

        $match = $this->product('Gold Ring In Range', [
            'category_id' => $rings->id,
            'price' => 1800,
            'stock_quantity' => 4,
        ]);
        $this->product('Gold Ring Expensive', [
            'category_id' => $rings->id,
            'price' => 9000,
            'stock_quantity' => 4,
        ]);
        $this->product('Gold Ring Out of Stock', [
            'category_id' => $rings->id,
            'price' => 1700,
            'stock_quantity' => 0,
        ]);
        $this->product('Gold Set Necklace', [
            'category_id' => $sets->id,
            'price' => 1900,
            'stock_quantity' => 6,
        ]);
        $this->product('Draft Ring', [
            'category_id' => $rings->id,
            'price' => 1800,
            'is_published' => false,
        ]);

        $rows = $this->search->mappingSuggestions(
            term: 'Gold',
            categoryId: (string) $rings->id,
            stock: 'in',
            priceMin: '1500',
            priceMax: '2000',
        );

        $this->assertCount(1, $rows);
        $this->assertSame($match->id, $rows[0]['id']);
        $this->assertSame('Gold Ring In Range', $rows[0]['name']);
        $this->assertSame(1800.0, $rows[0]['price']);
    }

    #[Test]
    public function inverted_price_bounds_are_swapped(): void
    {
        $this->assertSame(
            [100.0, 500.0],
            $this->search->normalizedPriceRange('500', '100'),
        );
    }

    #[Test]
    public function picker_results_include_unpublished_products_and_priced_flags(): void
    {
        $category = $this->category('Rings');
        $draft = $this->product('Unpublished Ring', [
            'category_id' => $category->id,
            'price' => 1200,
            'is_published' => false,
            'priced_image_path' => 'img/priced.jpg',
        ]);

        $rows = $this->search->pickerResults(
            term: 'Unpublished',
            categoryId: '',
            priceMin: '',
            priceMax: '',
        );

        $this->assertCount(1, $rows);
        $this->assertSame($draft->id, $rows[0]['id']);
        $this->assertTrue($rows[0]['has_priced_image']);
        $this->assertSame('Rings', $rows[0]['category']);
    }

    private function category(string $name): Category
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
    private function product(string $name, array $attributes = []): Product
    {
        return Product::query()->create(array_merge([
            'name' => $name,
            'slug' => str($name)->slug()->toString().'-'.uniqid(),
            'sku' => 'ICS'.random_int(1000, 9999),
            'price' => 1500,
            'purchase_price' => 700,
            'stock_quantity' => 5,
            'is_published' => true,
            'display_order' => 0,
        ], $attributes));
    }
}
