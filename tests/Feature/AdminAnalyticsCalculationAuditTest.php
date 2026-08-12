<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminAnalyticsOrdersWithCosts;
use App\Models\Courier;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Models\User;
use App\Services\Admin\OrderCalculationAuditService;
use App\Services\Orders\OrderEmptyProductDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminAnalyticsCalculationAuditTest extends TestCase
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
    public function audit_reports_zero_packaging_but_ignores_non_zero_mismatch(): void
    {
        $this->actingAs($this->adminUser());
        $courier = $this->steadfast();

        $zeroPack = Order::query()->create([
            'order_number' => 'AUD-ZERO-PACK',
            'name' => 'Zero Pack',
            'phone' => '01710000001',
            'address' => 'Dhaka',
            'city' => 'Dhaka',
            'status' => 'processing',
            'subtotal' => 500,
            'total' => 500,
            'packaging_cost' => 0,
            'courier_charge' => 60,
            'courier_id' => $courier->id,
            'placed_at' => '2025-03-01 10:00:00',
        ]);
        OrderProduct::query()->create([
            'order_id' => $zeroPack->id,
            'name' => 'Kurti',
            'quantity' => 1,
            'price' => 500,
            'purchase_price' => 200,
            'unit_cost' => 200,
            'line_total' => 500,
        ]);

        $mismatch = Order::query()->create([
            'order_number' => 'AUD-PACK-MISMATCH',
            'name' => 'Mismatch',
            'phone' => '01710000002',
            'address' => 'Dhaka',
            'city' => 'Dhaka',
            'status' => 'processing',
            'subtotal' => 500,
            'total' => 500,
            'packaging_cost' => 99,
            'courier_charge' => 90,
            'courier_id' => $courier->id,
            'placed_at' => '2025-03-01 10:00:00',
        ]);
        OrderProduct::query()->create([
            'order_id' => $mismatch->id,
            'name' => 'Kurti',
            'quantity' => 1,
            'price' => 500,
            'purchase_price' => 200,
            'unit_cost' => 200,
            'line_total' => 500,
        ]);

        Livewire::test(AdminAnalyticsOrdersWithCosts::class)
            ->assertSee('Audit columns…')
            ->call('openCalculationAuditModal')
            ->assertSet('auditModalOpen', true)
            ->assertSet('auditTotal', 2)
            ->call('startCalculationAudit')
            ->assertSet('auditDone', true)
            ->assertSet('auditManualNeeded', 1)
            ->assertSee('AUD-ZERO-PACK')
            ->assertSee('Packaging is ৳0')
            ->assertDontSee('differs from rate card');

        // Report-only: zero packaging is not auto-filled.
        $zeroPack->refresh();
        $this->assertSame(0.0, (float) $zeroPack->packaging_cost);
        $mismatch->refresh();
        $this->assertSame(99.0, (float) $mismatch->packaging_cost);
    }

    #[Test]
    public function audit_flags_missing_product_cogs_without_mutating(): void
    {
        $this->actingAs($this->adminUser());
        $courier = $this->steadfast();

        $product = Product::query()->create([
            'name' => 'No Cost Product',
            'slug' => 'no-cost-product',
            'sku' => 'NCP-1',
            'price' => 500,
            'purchase_price' => 0,
            'is_published' => true,
        ]);

        $missingCogs = Order::query()->create([
            'order_number' => 'AUD-NO-COGS',
            'name' => 'No Cogs',
            'phone' => '01710000004',
            'address' => 'Dhaka',
            'city' => 'Dhaka',
            'status' => 'processing',
            'subtotal' => 500,
            'total' => 500,
            'packaging_cost' => 21,
            'courier_charge' => 60,
            'courier_id' => $courier->id,
            'placed_at' => '2025-03-01 10:00:00',
        ]);
        OrderProduct::query()->create([
            'order_id' => $missingCogs->id,
            'product_id' => $product->id,
            'name' => 'No Cost Product',
            'quantity' => 1,
            'price' => 500,
            'purchase_price' => 0,
            'unit_cost' => 0,
            'line_total' => 500,
        ]);

        Livewire::test(AdminAnalyticsOrdersWithCosts::class)
            ->call('openCalculationAuditModal')
            ->call('startCalculationAudit')
            ->assertSet('auditDone', true)
            ->assertSet('auditManualNeeded', 1)
            ->assertSee('AUD-NO-COGS')
            ->assertSee('no unit cost');

        $this->assertSame(0.0, (float) $product->fresh()->unit_cost);
    }

    #[Test]
    public function audit_service_reports_zero_cogs_when_catalog_has_cost(): void
    {
        $courier = $this->steadfast();

        $product = Product::query()->create([
            'name' => 'Has Cost',
            'slug' => 'has-cost',
            'sku' => 'HC-1',
            'price' => 500,
            'purchase_price' => 180,
            'is_published' => true,
        ]);

        $order = Order::query()->create([
            'order_number' => 'AUD-BACKFILL',
            'name' => 'Backfill',
            'phone' => '01710000005',
            'address' => 'Dhaka',
            'city' => 'Dhaka',
            'status' => 'processing',
            'subtotal' => 500,
            'total' => 500,
            'packaging_cost' => 21,
            'courier_charge' => 60,
            'courier_id' => $courier->id,
            'placed_at' => '2025-03-01 10:00:00',
        ]);
        OrderProduct::query()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'name' => 'Has Cost',
            'quantity' => 1,
            'price' => 500,
            'purchase_price' => 0,
            'unit_cost' => 0,
            'line_total' => 500,
        ]);

        $result = app(OrderCalculationAuditService::class)->auditOrder($order->fresh(['items.product', 'courier']));

        $this->assertFalse($result['auto_fixed']);
        $this->assertNotEmpty($result['issues']);
        $this->assertSame(0.0, (float) $order->fresh('items')->items->first()->unit_cost);
    }

    #[Test]
    public function audit_flags_empty_order_missing_default_cogs(): void
    {
        $this->actingAs($this->adminUser());
        $courier = $this->steadfast();

        $order = Order::query()->create([
            'order_number' => 'AUD-EMPTY',
            'name' => 'No Products',
            'phone' => '01710000006',
            'address' => 'Dhaka',
            'city' => 'Dhaka',
            'status' => 'confirmed',
            'subtotal' => 0,
            'total' => 100,
            'packaging_cost' => 0,
            'courier_charge' => 0,
            'courier_id' => $courier->id,
            'placed_at' => '2024-06-01 10:00:00',
        ]);

        Livewire::test(AdminAnalyticsOrdersWithCosts::class)
            ->call('openCalculationAuditModal')
            ->call('startCalculationAudit')
            ->assertSet('auditDone', true)
            ->assertSet('auditManualNeeded', 1)
            ->assertSee('AUD-EMPTY');

        $order->refresh()->load('items');
        $this->assertSame(0.0, (float) $order->packaging_cost);
        $this->assertSame(0.0, $order->cogs());
        $this->assertFalse(
            $order->items->contains(
                fn ($item) => (string) $item->name === OrderEmptyProductDefaults::COGS_LINE_NAME
            )
        );
    }
}
