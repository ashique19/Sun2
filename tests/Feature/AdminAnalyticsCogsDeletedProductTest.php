<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminAnalyticsOrdersWithCosts;
use App\Models\Courier;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminAnalyticsCogsDeletedProductTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function steadfast(): Courier
    {
        return Courier::query()->create([
            'name' => 'Steadfast',
            'slug' => 'steadfast',
            'charge' => 60,
            'osd_charge' => 110,
            'cod_percentage' => 1,
            'is_active' => true,
            'is_default' => true,
        ]);
    }

    #[Test]
    public function cogs_modal_saves_line_cost_when_product_is_deleted_after_modal_opens(): void
    {
        $this->actingAs($this->adminUser());
        $courier = $this->steadfast();

        $product = Product::query()->create([
            'name' => 'Gone Product',
            'slug' => 'gone-product',
            'sku' => 'GONE-1',
            'price' => 500,
            'purchase_price' => 0,
            'is_published' => true,
        ]);

        $order = Order::query()->create([
            'order_number' => 'ORPHAN-COGS-1',
            'name' => 'Customer',
            'phone' => '01710000001',
            'address' => 'Dhaka',
            'city' => 'Dhaka',
            'status' => 'delivered',
            'subtotal' => 500,
            'delivery_charge' => 80,
            'courier_charge' => 60,
            'packaging_cost' => 21,
            'collected_amount' => 580,
            'total' => 580,
            'courier_id' => $courier->id,
            'actual_delivery_date' => now(),
            'placed_at' => now(),
        ]);

        $line = OrderProduct::query()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'name' => 'Gone Product',
            'quantity' => 1,
            'price' => 500,
            'purchase_price' => 0,
            'unit_cost' => 0,
            'line_total' => 500,
        ]);

        $component = Livewire::test(AdminAnalyticsOrdersWithCosts::class)
            ->call('openCogsModal', $order->id)
            ->assertSet('cogsModalRows.0.product_id', $product->id)
            ->set('cogsModalRows.0.purchase_price', '120')
            ->set('cogsModalRows.0.other_cost', '30');

        // Catalog row disappears while the modal still holds the old product_id.
        Product::query()->whereKey($product->id)->delete();

        $component
            ->call('saveCogsModalRow', 0)
            ->assertHasNoErrors()
            ->assertSee('Saved')
            ->assertSee('no linked product');

        $line->refresh();
        $this->assertNull($line->product_id);
        $this->assertSame(120.0, (float) $line->purchase_price);
        $this->assertSame(150.0, (float) $line->unit_cost);
        $this->assertSame(150.0, $order->fresh(['items'])->cogs());
    }

    #[Test]
    public function cogs_modal_treats_missing_product_id_as_line_only_save(): void
    {
        $this->actingAs($this->adminUser());
        $courier = $this->steadfast();

        $order = Order::query()->create([
            'order_number' => 'ORPHAN-COGS-2',
            'name' => 'Customer',
            'phone' => '01710000002',
            'address' => 'Dhaka',
            'city' => 'Dhaka',
            'status' => 'delivered',
            'subtotal' => 500,
            'total' => 500,
            'collected_amount' => 500,
            'courier_id' => $courier->id,
            'packaging_cost' => 21,
            'courier_charge' => 60,
            'placed_at' => now(),
        ]);

        $line = OrderProduct::query()->create([
            'order_id' => $order->id,
            'product_id' => null,
            'name' => 'Legacy line',
            'quantity' => 1,
            'price' => 500,
            'purchase_price' => 0,
            'unit_cost' => 0,
            'line_total' => 500,
        ]);

        Livewire::test(AdminAnalyticsOrdersWithCosts::class)
            ->call('openCogsModal', $order->id)
            ->assertSet('cogsModalRows.0.product_id', null)
            // Stale/bogus product id in the form state must not blow up.
            ->set('cogsModalRows.0.product_id', 424242)
            ->set('cogsModalRows.0.purchase_price', '80')
            ->set('cogsModalRows.0.other_cost', '20')
            ->call('saveCogsModalRow', 0)
            ->assertHasNoErrors()
            ->assertSee('Saved');

        $line->refresh();
        $this->assertNull($line->product_id);
        $this->assertSame(80.0, (float) $line->purchase_price);
        $this->assertSame(100.0, (float) $line->unit_cost);
    }
}
