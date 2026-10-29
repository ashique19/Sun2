<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Services\Admin\AdminOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OrderCreateReusesExistingPhoneUserTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function admin_order_create_reuses_existing_user_with_same_phone(): void
    {
        Role::findOrCreate('admin');
        Role::findOrCreate('customers');

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $existing = User::factory()->create([
            'name' => 'Existing Customer',
            'phone' => '8801717778899',
        ]);
        $existing->assignRole('customers');

        $product = Product::query()->create([
            'name' => 'Reuse Kurti',
            'slug' => 'reuse-kurti',
            'sku' => 'RK-PHONE-1',
            'price' => 500,
            'purchase_price' => 200,
            'stock_quantity' => 5,
            'is_published' => true,
        ]);

        $this->actingAs($admin);

        $order = app(AdminOrderService::class)->create(
            AdminOrderService::orderAttributesFromForm([
                'name' => 'New Name On Order',
                'phone' => '01717778899',
                'address' => 'House 9',
                'city' => 'Dhaka',
                'subtotal' => 500,
                'delivery_charge' => 80,
                'discount' => 0,
                'payment_method' => 'cod',
                'status' => 'new',
            ]),
            [[
                'product_id' => $product->id,
                'name' => $product->name,
                'quantity' => 1,
                'price' => 500.0,
                'purchase_price' => 200.0,
                'line_total' => 500.0,
                'product_image' => null,
            ]],
        );

        $this->assertSame($existing->id, $order->user_id);
        $this->assertSame(2, User::query()->count());
        $this->assertDatabaseHas('users', ['id' => $existing->id, 'phone' => '8801717778899']);
    }
}
