<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminProductShow;
use App\Livewire\Admin\AdminProducts;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminProductShowTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function product(): Product
    {
        return Product::query()->create([
            'name' => 'Gold Pendant',
            'slug' => 'gold-pendant',
            'sku' => 'GP-1',
            'price' => 1200,
            'purchase_price' => 500,
            'commission' => 80,
            'max_discount' => 50,
            'stock_quantity' => 12,
            'is_published' => true,
            'display_order' => 0,
        ]);
    }

    public function test_products_list_shows_commission_and_links_to_detail(): void
    {
        $this->actingAs($this->adminUser());
        $product = $this->product();

        Livewire::test(AdminProducts::class)
            ->assertSee('Commission')
            ->assertSee('৳ 80')
            ->assertSee($product->name)
            ->assertSee(route('admin.products.show', $product), false);
    }

    public function test_product_detail_page_shows_details_and_analytics(): void
    {
        $this->actingAs($this->adminUser());
        $product = $this->product();

        Livewire::test(AdminProductShow::class, ['product' => $product])
            ->assertSuccessful()
            ->assertSee('Gold Pendant')
            ->assertSee('Reseller commission')
            ->assertSee('Analytics')
            ->assertSee('Monthly performance')
            ->assertSee('80 / unit');
    }

    public function test_legacy_performance_route_redirects_to_show(): void
    {
        $this->actingAs($this->adminUser());
        $product = $this->product();

        $this->get(route('admin.products.performance', $product))
            ->assertRedirect(route('admin.products.show', $product));
    }
}
