<?php

namespace Tests\Feature;

use App\Livewire\StorefrontProduct;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StorefrontProductRelatedTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function product_page_shows_related_products_from_same_category(): void
    {
        $category = Category::query()->create([
            'name' => 'Earrings',
            'slug' => 'earrings',
            'is_active' => true,
            'is_homepage' => true,
            'display_order' => 1,
        ]);

        $otherCategory = Category::query()->create([
            'name' => 'Rings',
            'slug' => 'rings',
            'is_active' => true,
            'is_homepage' => false,
            'display_order' => 2,
        ]);

        $product = Product::query()->create([
            'name' => 'Main Earring',
            'slug' => 'main-earring',
            'sku' => 'ME-1',
            'price' => 400,
            'purchase_price' => 150,
            'stock_quantity' => 5,
            'is_published' => true,
            'display_order' => 0,
            'category_id' => $category->id,
        ]);

        $related = Product::query()->create([
            'name' => 'Sibling Earring',
            'slug' => 'sibling-earring',
            'sku' => 'SE-1',
            'price' => 450,
            'purchase_price' => 160,
            'stock_quantity' => 4,
            'is_published' => true,
            'display_order' => 1,
            'category_id' => $category->id,
        ]);

        Product::query()->create([
            'name' => 'Other Category Ring',
            'slug' => 'other-ring',
            'sku' => 'OR-1',
            'price' => 500,
            'purchase_price' => 200,
            'stock_quantity' => 3,
            'is_published' => true,
            'display_order' => 0,
            'category_id' => $otherCategory->id,
        ]);

        Product::query()->create([
            'name' => 'Draft Sibling',
            'slug' => 'draft-sibling',
            'sku' => 'DS-1',
            'price' => 200,
            'purchase_price' => 80,
            'stock_quantity' => 1,
            'is_published' => false,
            'display_order' => 2,
            'category_id' => $category->id,
        ]);

        Livewire::test(StorefrontProduct::class, ['product' => $product])
            ->assertSee(__('storefront.related_products'))
            ->assertSee('Sibling Earring')
            ->assertDontSee('Other Category Ring')
            ->assertDontSee('Draft Sibling');

        $this->get(route('product.show', $related))
            ->assertOk()
            ->assertSee('Main Earring')
            ->assertSee(__('storefront.related_products'));
    }

    #[Test]
    public function product_without_category_omits_related_section(): void
    {
        $product = Product::query()->create([
            'name' => 'Standalone',
            'slug' => 'standalone',
            'sku' => 'ST-1',
            'price' => 300,
            'purchase_price' => 100,
            'stock_quantity' => 2,
            'is_published' => true,
            'display_order' => 0,
        ]);

        Livewire::test(StorefrontProduct::class, ['product' => $product])
            ->assertDontSee(__('storefront.related_products'));
    }
}
