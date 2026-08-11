<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminAnalyticsOrdersWithCosts;
use App\Models\Courier;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Models\User;
use App\Services\Admin\OrderCalculationAuditService;
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
    public function audit_auto_fixes_zero_packaging_and_reports_packaging_mismatch(): void
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
            'courier_charge' => 60,
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
            ->assertSee('Audit calculations…')
            ->call('openCalculationAuditModal')
            ->assertSet('auditModalOpen', true)
            ->assertSet('auditTotal', 2)
            ->call('startCalculationAudit')
            ->assertSet('auditDone', true)
            ->assertSet('auditAutoFixed', 1)
            ->assertSet('auditManualNeeded', 1)
            ->assertSee('AUD-PACK-MISMATCH')
            ->assertSee('differs from rate card')
            ->assertSeeHtml(route('admin.orders.show', $mismatch));

        $zeroPack->refresh();
        $this->assertSame(21.0, (float) $zeroPack->packaging_cost);

        $mismatch->refresh();
        $this->assertSame(99.0, (float) $mismatch->packaging_cost);
    }

    #[Test]
    public function audit_auto_fixes_legacy_paid_and_flags_missing_product_cogs(): void
    {
        $this->actingAs($this->adminUser());
        $courier = $this->steadfast();

        $paid = Order::query()->create([
            'order_number' => 'AUD-PAID',
            'name' => 'Legacy',
            'phone' => '01710000003',
            'address' => 'Dhaka',
            'city' => 'Dhaka',
            'status' => 'paid',
            'subtotal' => 1000,
            'delivery_charge' => 80,
            'courier_charge' => 60,
            'packaging_cost' => 21,
            'total' => 1080,
            'collected_amount' => 0,
            'paid_amount' => 0,
            'due_amount' => 1080,
            'cod_amount' => 1080,
            'payment_status' => 'unpaid',
            'courier_id' => $courier->id,
            'placed_at' => '2025-03-01 10:00:00',
        ]);
        OrderProduct::query()->create([
            'order_id' => $paid->id,
            'name' => 'Item',
            'quantity' => 1,
            'price' => 1000,
            'purchase_price' => 400,
            'unit_cost' => 400,
            'line_total' => 1000,
        ]);

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
            ->assertSet('auditAutoFixed', 1)
            ->assertSet('auditManualNeeded', 1)
            ->assertSee('AUD-NO-COGS')
            ->assertSee('no unit cost set');

        $paid->refresh();
        $this->assertSame('delivered', $paid->status);
        $this->assertSame(1080.0, (float) $paid->paid_amount);
    }

    #[Test]
    public function audit_service_backfills_cogs_from_product_catalog(): void
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

        $result = app(OrderCalculationAuditService::class)->auditOrder($order->fresh());

        $this->assertTrue($result['auto_fixed']);
        $this->assertSame([], $result['issues']);
        $this->assertSame(180.0, (float) $order->fresh('items')->items->first()->unit_cost);
    }
}
