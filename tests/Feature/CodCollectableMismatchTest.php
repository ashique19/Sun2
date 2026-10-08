<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminOrderShow;
use App\Models\Courier;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\Orders\OrderDeliverySettlement;
use App\Services\Orders\OrderPaymentRecorder;
use App\Services\Orders\OrderPaymentSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CodCollectableMismatchTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function seedCodMethod(): void
    {
        PaymentMethod::query()->firstOrCreate(
            ['code' => 'cod'],
            ['name' => 'COD', 'is_active' => true],
        );
        PaymentMethod::query()->firstOrCreate(
            ['code' => 'bkash'],
            ['name' => 'bKash', 'is_active' => true],
        );
    }

    private function steadfast(): Courier
    {
        return Courier::query()->create([
            'name' => 'Steadfast',
            'slug' => 'steadfast',
            'cod_percentage' => 1,
            'is_active' => true,
            'is_default' => true,
        ]);
    }

    private function order(Courier $courier, array $overrides = []): Order
    {
        $order = Order::query()->create(array_merge([
            'order_number' => 'COD-MIS-'.uniqid(),
            'name' => 'COD Customer',
            'phone' => '01710003170',
            'address' => 'Dhaka',
            'city' => 'Dhaka',
            'area' => 'Mirpur',
            'status' => 'dispatched',
            'subtotal' => 3050,
            'delivery_charge' => 120,
            'courier_charge' => 115,
            'packaging_cost' => 54,
            'charge' => 0,
            'discount' => 0,
            'total' => 3170,
            'cod_amount' => 3170,
            'due_amount' => 3170,
            'paid_amount' => 0,
            'collected_amount' => 0,
            'payment_status' => 'unpaid',
            'payment_method' => 'cod',
            'courier_id' => $courier->id,
            'dispatch_date' => now()->subDay(),
            'placed_at' => now()->subDays(2),
            'placed_via' => Order::PLACED_VIA_ADMIN,
        ], $overrides));

        OrderProduct::query()->create([
            'order_id' => $order->id,
            'name' => 'Necklace',
            'quantity' => 1,
            'price' => 3050,
            'purchase_price' => 1910,
            'line_total' => 3050,
        ]);

        return $order->fresh(['items', 'courier', 'paymentTransactions']);
    }

    #[Test]
    public function premature_courier_cod_settlement_does_not_zero_collectable_while_dispatched(): void
    {
        $this->seedCodMethod();
        $courier = $this->steadfast();
        $order = $this->order($courier);

        // Simulate a courier COD settlement written while still dispatched (no delivery date).
        PaymentTransaction::query()->create([
            'order_id' => $order->id,
            'method' => 'cod',
            'amount' => 3170,
            'status' => 'completed',
            'kind' => 'settlement',
            'external_id' => OrderDeliverySettlement::settlementExternalId((int) $order->id),
            'paid_at' => now(),
            'meta' => ['source' => 'steadfast_webhook'],
        ]);

        // Old sync behaviour would set paid=3170 / collectable=0 / collected=3170.
        $order->forceFill([
            'paid_amount' => 3170,
            'due_amount' => 0,
            'cod_amount' => 0,
            'collected_amount' => 3170,
            'payment_status' => 'paid',
        ])->save();

        app(OrderPaymentSync::class)->sync($order->fresh(['paymentTransactions']));
        $order = $order->fresh(['items', 'courier', 'paymentTransactions', 'adjustments']);

        $this->assertSame(3170.0, $order->moneyTotals()->billToCustomer);
        $this->assertSame(0.0, (float) $order->paid_amount);
        $this->assertSame(3170.0, (float) $order->due_amount);
        $this->assertSame(3170.0, (float) $order->cod_amount);
        $this->assertSame(0.0, (float) $order->collected_amount);
        $this->assertSame('unpaid', $order->payment_status);
        $this->assertSame(3170.0, $order->collectableAmount());

        // COD % follows remittance (expected collectable), not a stale collected_amount.
        $this->assertSame(30.5, $order->codCharge());
    }

    #[Test]
    public function admin_order_show_heals_premature_cod_settlement_to_full_collectable(): void
    {
        $this->seedCodMethod();
        $courier = $this->steadfast();
        $order = $this->order($courier);

        PaymentTransaction::query()->create([
            'order_id' => $order->id,
            'method' => 'cod',
            'amount' => 3170,
            'status' => 'completed',
            'kind' => 'settlement',
            'external_id' => OrderDeliverySettlement::settlementExternalId((int) $order->id),
            'paid_at' => now(),
        ]);

        $order->forceFill([
            'paid_amount' => 3170,
            'due_amount' => 0,
            'cod_amount' => 0,
            'collected_amount' => 3170,
            'payment_status' => 'paid',
        ])->save();

        $this->actingAs($this->adminUser());

        Livewire::test(AdminOrderShow::class, ['order' => $order->fresh()])
            ->assertSee('Bill to customer')
            ->assertSee('Amount to collect')
            ->assertSeeHtml('&#2547; 3,170')
            ->assertDontSee('After &#2547;3,170 paid');

        $order->refresh();
        $this->assertSame(3170.0, $order->collectableAmount());
        $this->assertSame(0.0, (float) $order->paid_amount);
        $this->assertSame('unpaid', $order->payment_status);
    }

    #[Test]
    public function delivered_cod_settlement_zeros_collectable_and_applies_cod_charge(): void
    {
        $this->seedCodMethod();
        $courier = $this->steadfast();
        $order = $this->order($courier, [
            'status' => 'delivered',
            'actual_delivery_date' => now(),
        ]);

        app(OrderPaymentRecorder::class)->record(
            order: $order,
            method: 'cod',
            amount: 3170,
            kind: 'settlement',
            reference: OrderDeliverySettlement::settlementExternalId((int) $order->id),
        );

        $order = $order->fresh(['items', 'courier', 'paymentTransactions', 'adjustments']);

        $this->assertSame(0.0, $order->collectableAmount());
        $this->assertSame(3170.0, (float) $order->collected_amount);
        $this->assertSame(30.5, $order->codCharge());
        $this->assertSame('paid', $order->payment_status);
    }

    #[Test]
    public function full_bkash_prepaid_has_zero_collectable_and_zero_cod_charge(): void
    {
        $this->seedCodMethod();
        $courier = $this->steadfast();
        $order = $this->order($courier, ['status' => 'new', 'dispatch_date' => null]);

        app(OrderPaymentRecorder::class)->record(
            order: $order,
            method: 'bkash',
            amount: 3170,
            kind: 'advance',
        );

        $order = $order->fresh(['items', 'courier', 'paymentTransactions', 'adjustments']);

        $this->assertSame(0.0, $order->collectableAmount());
        $this->assertSame(0.0, (float) $order->collected_amount);
        $this->assertSame(0.0, $order->codCharge());
        $this->assertSame(0.0, $order->moneyTotals()->remittanceBase);
    }
}
