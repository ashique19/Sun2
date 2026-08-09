<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminProducts;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Support\AdminProductListFilters;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminProductListFiltersTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    #[Test]
    public function products_list_persists_filters_to_session(): void
    {
        $this->actingAs($this->adminUser());

        $category = Category::query()->create([
            'name' => 'Rings',
            'slug' => 'rings',
        ]);

        Livewire::test(AdminProducts::class)
            ->set('search', 'gold')
            ->set('category', (string) $category->id)
            ->set('published', '1')
            ->set('priceMin', '100')
            ->set('priceMax', '500');

        $stored = AdminProductListFilters::recall();

        $this->assertSame('gold', $stored->search);
        $this->assertSame((string) $category->id, $stored->category);
        $this->assertSame('1', $stored->published);
        $this->assertSame('100', $stored->priceMin);
        $this->assertSame('500', $stored->priceMax);
    }

    #[Test]
    public function apply_scopes_product_query(): void
    {
        $category = Category::query()->create([
            'name' => 'Sets',
            'slug' => 'sets',
        ]);

        Product::query()->create([
            'name' => 'Gold Set',
            'slug' => 'gold-set',
            'sku' => 'GS-1',
            'price' => 1200,
            'category_id' => $category->id,
            'is_published' => true,
            'display_order' => 1,
        ]);
        Product::query()->create([
            'name' => 'Silver Ring',
            'slug' => 'silver-ring',
            'sku' => 'SR-1',
            'price' => 400,
            'is_published' => true,
            'display_order' => 2,
        ]);

        $ids = AdminProductListFilters::fromArray([
            'search' => 'gold',
            'priceMin' => '1000',
        ])->apply(Product::query())->pluck('slug')->all();

        $this->assertSame(['gold-set'], $ids);
    }
}
