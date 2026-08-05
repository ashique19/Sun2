<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminOrders;
use App\Models\Courier;
use App\Models\CourierBalanceEntry;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\User;
use App\Services\Admin\CourierBalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminOrdersPartialReturnCollectedTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    /**
     * @return array{0: Order, 1: OrderProduct, 2: Courier}
     */
    private function dispatchedOrder(): array
    {
        $courier = Courier::query()->create([
            'name' => 'Steadfast',
            'slug' => 'steadfast',
            'charge' => 60,
            'osd_charge' => 110,
            'cod_percentage' => 1,
            'balance' => 0,
            'is_active' => true,
            'is_default' => true,
        ]);

        $product = Product::query()->create([
            'name' => 'Partial Saree',
            'slug' => 'partial-saree-'.uniqid(),
            'price' => 1000,
            'purchase_price' => 400,
            'stock_quantity' => 5,
            'is_published' => true,
        ]);

        $order = Order::query()->create([
            'order_number' => 'PR-'.uniqid(),
            'name' => 'Partial Customer',
            'phone' => '01710000888',
            'address' => 'Dhaka',
            'city' => 'Dhaka',
            'status' => 'dispatched',
            'subtotal' => 1000,
            'delivery_charge' => 80,
            'courier_charge' => 60,
            'total' => 1080,
            'cod_amount' => 1080,
            'due_amount' => 1080,
            'paid_amount' => 0,
            'payment_status' => 'unpaid',
            'courier_id' => $courier->id,
            'dispatch_date' => now(),
            'placed_at' => now(),
        ]);

        $item = OrderProduct::query()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'name' => $product->name,
            'quantity' => 2,
            'price' => 500,
            'purchase_price' => 200,
            'line_total' => 1000,
        ]);

        app(CourierBalanceService::class)->creditOnDispatch($courier, $order);

        return [$order->fresh(), $item, $courier->fresh()];
    }

    #[Test]
    public function partial_return_records_rider_collected_amount_without_subtracting_courier_charge(): void
    {
        $this->actingAs($this->adminUser());
        [$order, $item, $courier] = $this->dispatchedOrder();

        $expectedCod = 1080.0;
        $courierCharge = 60.0;
        // Rider collected full expected COD from the customer.
        $riderCollected = $expectedCod;

        Livewire::test(AdminOrders::class, ['segment' => 'dispatched'])
            ->call('openPartialReturn', $order->id)
            ->assertSet('partialCollectedTk', '1080')
            ->assertSee('Expected COD')
            ->assertSee('rider collected')
            ->set('partialReturns.'.$item->id, 1)
            ->set('partialCollectedTk', (string) (int) $riderCollected)
            ->call('submitPartialReturn');

        $order->refresh()->load('courier');
        $courier->refresh();

        $this->assertSame('delivered', $order->status);
        $this->assertTrue((bool) $order->has_return);

        // Collected / paid must equal rider amount — not rider − courier charge.
        $this->assertSame($riderCollected, (float) $order->collected_amount);
        $this->assertSame($riderCollected, (float) $order->paid_amount);
        $this->assertSame(0.0, (float) $order->due_amount);
        $this->assertNotSame($riderCollected - $courierCharge, (float) $order->collected_amount);

        $codTxn = PaymentTransaction::query()
            ->where('order_id', $order->id)
            ->where('method', 'cod')
            ->sole();
        $this->assertSame($riderCollected, (float) $codTxn->amount);

        $partialCredit = CourierBalanceEntry::query()
            ->where('order_id', $order->id)
            ->where('type', 'dispatch')
            ->where('note', 'like', 'Partial collect%')
            ->sole();
        $this->assertSame((int) $riderCollected, (int) $partialCredit->amount);
        $this->assertSame((float) $riderCollected, (float) $courier->balance);

        // Remittance base stays gross; courier fee is applied only when computing receivable.
        $money = $order->moneyTotals();
        $this->assertSame($riderCollected, $money->remittanceBase);
        $this->assertSame(10.0, $money->codCharge); // (1080 - 80) × 1%
        $this->assertSame($riderCollected - $courierCharge - 10.0, $money->courierReceivable);
    }
}
