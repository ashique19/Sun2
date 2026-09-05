<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminOrderShow;
use App\Models\Area;
use App\Models\City;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Orders\OrderPaymentSync;
use App\Services\Storefront\CartService;
use App\Services\Storefront\CheckoutPricing;
use App\Services\Storefront\OrderPlacer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StorefrontCodCollectableTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('customers');
        Role::findOrCreate('admin');
    }

    /**
     * @return array{0: Product, 1: Area}
     */
    private function seedCheckout(float $price = 500): array
    {
        $product = Product::query()->create([
            'name' => 'COD Collectable Kurti',
            'slug' => 'cod-collectable-kurti-'.uniqid(),
            'sku' => 'COD-COLLECT-'.uniqid(),
            'price' => $price,
            'purchase_price' => 200,
            'stock_quantity' => 10,
            'is_published' => true,
            'display_order' => 0,
        ]);

        $city = City::query()->create([
            'name' => 'Dhaka',
            'slug' => 'dhaka-cod-collectable-'.uniqid(),
            'division' => 'Dhaka',
            'is_dhaka' => true,
            'is_active' => true,
        ]);

        $area = Area::query()->create([
            'city_id' => $city->id,
            'name' => 'Uttara',
            'slug' => 'dhaka-uttara-cod-collectable-'.uniqid(),
            'is_active' => true,
            'delivery_charge_upto_5' => 80,
            'delivery_charge_over_5' => 120,
        ]);

        return [$product, $area];
    }

    private function placeStorefrontOrder(float $price = 500): Order
    {
        [$product, $area] = $this->seedCheckout($price);

        $cart = app(CartService::class);
        $cart->clear();
        $cart->add($product->id, 1);

        $pricing = CheckoutPricing::calculate(
            $cart->subtotal(),
            $area,
            $cart->count(),
            [],
            $cart->lines(),
        );

        return app(OrderPlacer::class)->place($cart, $pricing, [
            'name' => 'COD Customer',
            'phone' => '01710000580',
            'email' => '',
            'address' => 'House 580',
            'area' => $area->name,
            'city' => $area->city->name,
            'state' => 'Dhaka',
        ]);
    }

    #[Test]
    public function storefront_cod_order_collectable_matches_bill_to_customer(): void
    {
        $order = $this->placeStorefrontOrder(500);
        $order = $order->fresh(['items', 'adjustments', 'paymentTransactions']);

        $bill = $order->moneyTotals()->billToCustomer;
        $collectable = $order->collectableAmount();

        $this->assertSame(580.0, $bill);
        $this->assertSame(580.0, (float) $order->total);
        $this->assertSame(0.0, (float) $order->paid_amount);
        $this->assertSame(580.0, (float) $order->due_amount);
        $this->assertSame(580.0, (float) $order->cod_amount);
        $this->assertSame('unpaid', $order->payment_status);
        $this->assertSame(0, $order->paymentTransactions->count());
        $this->assertSame($bill, $collectable);
    }

    #[Test]
    public function stale_zero_total_with_positive_line_bill_yields_collectable_zero(): void
    {
        // Hypothesis A: orders.total/cod/due wiped to 0 while line economics still bill 580.
        $order = $this->placeStorefrontOrder(500);
        $order->forceFill([
            'total' => 0,
            'cod_amount' => 0,
            'due_amount' => 0,
            'paid_amount' => 0,
            'payment_status' => 'unpaid',
        ])->save();

        $order = $order->fresh(['items', 'adjustments']);

        $bill = $order->moneyTotals()->billToCustomer;
        $collectable = $order->collectableAmount();

        $this->assertSame(580.0, $bill);
        $this->assertSame(0.0, (float) $order->total);
        $this->assertSame(0.0, $collectable);
    }

    #[Test]
    public function payment_sync_with_zero_total_zeros_cod_and_due_caches(): void
    {
        // Hypothesis B: OrderPaymentSync while total=0 overwrites placer-set cod/due.
        $order = $this->placeStorefrontOrder(500);
        $order->forceFill(['total' => 0])->save();

        app(OrderPaymentSync::class)->sync($order->fresh());
        $order = $order->fresh();

        $this->assertSame(0.0, (float) $order->paid_amount);
        $this->assertSame(0.0, (float) $order->due_amount);
        $this->assertSame(0.0, (float) $order->cod_amount);
        $this->assertSame(0.0, $order->collectableAmount());
        $this->assertSame(580.0, $order->moneyTotals()->billToCustomer);
    }

    #[Test]
    public function admin_order_show_surfaces_collectable_mismatch_when_total_stale(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $order = $this->placeStorefrontOrder(500);
        $order->forceFill([
            'total' => 0,
            'cod_amount' => 0,
            'due_amount' => 0,
            'paid_amount' => 0,
        ])->save();

        $this->actingAs($admin);

        Livewire::test(AdminOrderShow::class, ['order' => $order->fresh()])
            ->assertSee('Bill to customer')
            ->assertSee('Amount to collect')
            ->assertSeeHtml('&#2547; 580')
            ->assertSeeHtml('&#2547; 0');
    }
}
