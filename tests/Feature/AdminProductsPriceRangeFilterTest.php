<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminProducts;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminProductsPriceRangeFilterTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function seedPricedProducts(): void
    {
        Product::query()->create([
            'name' => 'Budget Ring',
            'slug' => 'budget-ring',
            'sku' => 'BR-1',
            'price' => 400,
            'is_published' => true,
            'display_order' => 1,
        ]);
        Product::query()->create([
            'name' => 'Mid Necklace',
            'slug' => 'mid-necklace',
            'sku' => 'MN-1',
            'price' => 900,
            'is_published' => true,
            'display_order' => 2,
        ]);
        Product::query()->create([
            'name' => 'Premium Set',
            'slug' => 'premium-set',
            'sku' => 'PS-1',
            'price' => 2500,
            'is_published' => true,
            'display_order' => 3,
        ]);
    }

    #[Test]
    public function products_list_exposes_min_and_max_price_filters(): void
    {
        $this->actingAs($this->adminUser());

        Livewire::test(AdminProducts::class)
            ->assertSeeHtml('placeholder="Min price"')
            ->assertSeeHtml('placeholder="Max price"')
            ->assertSeeHtml('placeholder="Search name, SKU…"')
            ->assertDontSeeHtml('placeholder="Search name, SKU, price…"');
    }

    #[Test]
    public function admin_can_filter_products_by_price_range(): void
    {
        $this->actingAs($this->adminUser());
        $this->seedPricedProducts();

        Livewire::test(AdminProducts::class)
            ->set('priceMin', '500')
            ->set('priceMax', '1000')
            ->assertSee('Mid Necklace')
            ->assertDontSee('Budget Ring')
            ->assertDontSee('Premium Set');
    }

    #[Test]
    public function admin_can_filter_with_only_min_or_only_max_price(): void
    {
        $this->actingAs($this->adminUser());
        $this->seedPricedProducts();

        Livewire::test(AdminProducts::class)
            ->set('priceMin', '1000')
            ->assertSee('Premium Set')
            ->assertDontSee('Budget Ring')
            ->assertDontSee('Mid Necklace');

        Livewire::test(AdminProducts::class)
            ->set('priceMax', '500')
            ->assertSee('Budget Ring')
            ->assertDontSee('Mid Necklace')
            ->assertDontSee('Premium Set');
    }

    #[Test]
    public function swapped_min_and_max_still_filter_the_inclusive_range(): void
    {
        $this->actingAs($this->adminUser());
        $this->seedPricedProducts();

        // 2000 / 800 is treated as 800–2000.
        Livewire::test(AdminProducts::class)
            ->set('priceMin', '2000')
            ->set('priceMax', '800')
            ->assertSee('Mid Necklace')
            ->assertDontSee('Budget Ring')
            ->assertDontSee('Premium Set');
    }

    #[Test]
    public function text_search_no_longer_matches_a_single_price_on_admin_list(): void
    {
        $this->actingAs($this->adminUser());
        $this->seedPricedProducts();

        Livewire::test(AdminProducts::class)
            ->set('search', '900')
            ->assertDontSee('Mid Necklace')
            ->assertDontSee('Budget Ring')
            ->assertDontSee('Premium Set');

        Livewire::test(AdminProducts::class)
            ->set('search', 'MN-1')
            ->assertSee('Mid Necklace')
            ->assertDontSee('Budget Ring');
    }
}
