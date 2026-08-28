<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminOrderForm;
use App\Models\Area;
use App\Models\City;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\OrderAdjustment;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminOrderFormAdjustmentsTest extends TestCase
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
            'name' => 'Test Earring',
            'slug' => 'test-earring-'.uniqid(),
            'price' => 500,
            'purchase_price' => 200,
            'stock_quantity' => 5,
            'is_published' => true,
            'display_order' => 0,
        ]);
    }

    #[Test]
    public function order_form_saves_multiple_adjustment_lines_and_courier_cost(): void
    {
        $admin = $this->adminUser();
        $product = $this->product();

        $city = City::query()->create(['name' => 'Dhaka', 'is_active' => true, 'is_dhaka' => true]);
        $area = Area::query()->create([
            'city_id' => $city->id,
            'name' => 'Mirpur',
            'is_active' => true,
            'delivery_charge_upto_5' => 80,
            'delivery_charge_over_5' => 120,
        ]);

        $order = Order::query()->create([
            'order_number' => '9001',
            'name' => 'Customer',
            'phone' => '01711111111',
            'address' => 'Mirpur, Dhaka',
            'city' => 'Dhaka',
            'area' => 'Mirpur',
            'status' => 'new',
            'subtotal' => 500,
            'delivery_charge' => 80,
            'charge' => 0,
            'discount' => 0,
            'total' => 580,
            'courier_charge' => 0,
            'payment_method' => 'cod',
            'placed_via' => Order::PLACED_VIA_ADMIN,
        ]);

        $this->actingAs($admin);

        Livewire::test(AdminOrderForm::class, ['order' => $order])
            ->set('name', 'Customer')
            ->set('phone', '01711111111')
            ->set('address', 'Mirpur, Dhaka')
            ->set('cityId', $city->id)
            ->set('areaId', $area->id)
            ->set('autoDelivery', false)
            ->set('deliveryCharge', '80')
            ->set('lines', [
                $product->id => [
                    'product_id' => $product->id,
                    'name' => $product->name,
                    'quantity' => 1,
                    'price' => 500.0,
                    'purchase_price' => 200.0,
                    'unit_cost' => 200.0,
                    'line_total' => 500.0,
                    'product_image' => null,
                    'stock_quantity' => 5,
                ],
            ])
            ->call('addChargeLine')
            ->set('adjustmentLines.0.label', 'Gift wrap')
            ->set('adjustmentLines.0.amount', '50')
            ->call('addDiscountLine')
            ->set('adjustmentLines.1.label', 'Staff discount')
            ->set('adjustmentLines.1.amount', '30')
            ->set('courierChargeInput', '65')
            ->call('save')
            ->assertHasNoErrors();

        $order->refresh();

        $this->assertSame(600.0, (float) $order->total);
        $this->assertSame(50.0, (float) $order->charge);
        $this->assertSame(30.0, (float) $order->discount);
        $this->assertSame(65.0, (float) $order->courier_charge);

        $adjustments = OrderAdjustment::query()
            ->where('order_id', $order->id)
            ->orderBy('sort_order')
            ->get();

        $this->assertCount(2, $adjustments);
        $this->assertSame('charge', $adjustments[0]->type);
        $this->assertSame('Gift wrap', $adjustments[0]->label);
        $this->assertSame(50.0, (float) $adjustments[0]->amount);
        $this->assertSame('discount', $adjustments[1]->type);
        $this->assertSame('Staff discount', $adjustments[1]->label);
    }

    #[Test]
    public function order_form_can_add_coupon_adjustment_line(): void
    {
        $admin = $this->adminUser();
        $product = $this->product();

        Coupon::query()->create([
            'code' => 'SAVE50',
            'type' => 'fixed',
            'value' => 50,
            'min_order' => 0,
            'is_active' => true,
        ]);

        $order = Order::query()->create([
            'order_number' => '9002',
            'name' => 'Customer',
            'phone' => '01712222222',
            'address' => 'Dhaka',
            'city' => 'Dhaka',
            'status' => 'new',
            'subtotal' => 500,
            'delivery_charge' => 80,
            'charge' => 0,
            'discount' => 0,
            'total' => 580,
            'payment_method' => 'cod',
            'placed_via' => Order::PLACED_VIA_ADMIN,
        ]);

        $this->actingAs($admin);

        Livewire::test(AdminOrderForm::class, ['order' => $order])
            ->set('name', 'Customer')
            ->set('phone', '01712222222')
            ->set('address', 'Dhaka')
            ->set('autoDelivery', false)
            ->set('deliveryCharge', '80')
            ->set('lines', [
                $product->id => [
                    'product_id' => $product->id,
                    'name' => $product->name,
                    'quantity' => 1,
                    'price' => 500.0,
                    'purchase_price' => 200.0,
                    'unit_cost' => 200.0,
                    'line_total' => 500.0,
                    'product_image' => null,
                    'stock_quantity' => 5,
                ],
            ])
            ->set('couponCodeInput', 'SAVE50')
            ->call('applyCouponCode')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('order_adjustments', [
            'order_id' => $order->id,
            'type' => 'coupon',
            'label' => 'SAVE50',
            'amount' => 50,
        ]);

        $order->refresh();
        $this->assertSame(530.0, (float) $order->total);
    }

    #[Test]
    public function create_order_form_renders_stacked_adjustments_markup(): void
    {
        $this->actingAs($this->adminUser());

        Livewire::test(AdminOrderForm::class)
            ->assertSee('Adjustments')
            ->assertSee('+ Charge')
            ->assertSee('+ Discount')
            ->assertSee('Coupon code')
            ->assertSee('Add coupon')
            ->call('addChargeLine')
            ->assertSee('Remove')
            ->assertSee('Amount');
    }
}
