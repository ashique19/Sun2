<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminOrderForm;
use App\Livewire\Admin\AdminOrders;
use App\Models\Order;
use App\Models\OrderAdjustment;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Models\User;
use App\Services\Admin\CustomerLookupService;
use App\Services\Admin\OrderDeliveryReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminOrderExchangeLinkTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function product(string $name = 'Kurti', float $price = 1100): Product
    {
        return Product::query()->create([
            'name' => $name,
            'slug' => strtolower(str_replace(' ', '-', $name)).'-'.uniqid(),
            'sku' => 'SKU-'.uniqid(),
            'price' => $price,
            'purchase_price' => 400,
            'stock_quantity' => 20,
            'is_published' => true,
            'display_order' => 0,
        ]);
    }

    /**
     * @return array{0: Order, 1: OrderProduct, 2: Product}
     */
    private function deliveredOriginal(?Product $product = null, int $quantity = 1): array
    {
        $product ??= $this->product();
        $lineTotal = $quantity * 1100;

        $order = Order::query()->create([
            'order_number' => 'ORIG-'.uniqid(),
            'name' => 'Original Customer',
            'phone' => '01710000011',
            'address' => 'Dhaka',
            'status' => 'delivered',
            'subtotal' => $lineTotal,
            'delivery_charge' => 80,
            'total' => $lineTotal + 80,
            'collected_amount' => $lineTotal + 80,
            'paid_amount' => $lineTotal + 80,
            'due_amount' => 0,
            'has_return' => false,
            'is_replacement' => false,
            'actual_delivery_date' => now()->subDay(),
            'placed_at' => now()->subDays(2),
        ]);

        $item = OrderProduct::query()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'name' => $product->name,
            'quantity' => $quantity,
            'returned_quantity' => 0,
            'price' => 1100,
            'purchase_price' => 400,
            'unit_cost' => 400,
            'line_total' => $lineTotal,
        ]);

        return [$order, $item, $product];
    }

    private function mockCustomerLookup(): void
    {
        $lookup = Mockery::mock(CustomerLookupService::class);
        $lookup->shouldReceive('lookup')->andReturn([
            'phone' => '01710000011',
            'valid' => true,
            'user' => null,
            'last_order' => null,
            'order_count' => 0,
            'orders' => collect(),
            'steadfast' => null,
            'steadfast_error' => null,
        ])->byDefault();
        $lookup->shouldReceive('findOrCreateCustomer')->andReturnUsing(function (string $phone, string $name) {
            Role::findOrCreate('customers');

            $user = User::query()->where('phone', $phone)->first();
            if ($user) {
                return $user;
            }

            $user = User::factory()->create([
                'name' => $name,
                'phone' => $phone,
            ]);
            $user->assignRole('customers');

            return $user;
        })->byDefault();
        $lookup->shouldReceive('formDefaultsFromOrder')->andReturn([
            'name' => '',
            'email' => '',
            'address' => '',
            'cityId' => null,
            'areaId' => null,
            'location_hint' => null,
        ])->byDefault();
        $this->app->instance(CustomerLookupService::class, $lookup);
    }

    #[Test]
    public function creating_linked_exchange_settles_original_and_skips_hr_on_replacement(): void
    {
        $this->actingAs($this->adminUser());
        $this->mockCustomerLookup();
        [$original, $item, $product] = $this->deliveredOriginal();

        Livewire::test(AdminOrderForm::class)
            ->set('phone', '01710000011')
            ->set('name', 'Original Customer')
            ->set('address', 'Dhaka')
            ->set('autoDelivery', false)
            ->set('deliveryCharge', '0')
            ->set('isExchange', true)
            ->call('selectExchangeOf', $original->id)
            ->set('lines', [
                $product->id => [
                    'product_id' => $product->id,
                    'name' => $product->name,
                    'quantity' => 1,
                    'price' => 0.0,
                    'purchase_price' => 400.0,
                    'unit_cost' => 400.0,
                    'line_total' => 0.0,
                    'product_image' => null,
                    'stock_quantity' => 20,
                ],
            ])
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $replacement = Order::query()->where('id', '!=', $original->id)->first();
        $this->assertNotNull($replacement);
        $this->assertTrue($replacement->is_replacement);
        $this->assertSame($original->id, (int) $replacement->exchange_of_order_id);
        $this->assertFalse($replacement->has_return);
        $this->assertStringContainsString('[EXCHANGE PARCEL]', (string) $replacement->address);

        $original->refresh()->load(['items', 'adjustments']);
        $this->assertTrue($original->has_return);
        $this->assertSame('delivered', $original->status);
        $this->assertSame(0, (int) $original->items->firstWhere('id', $item->id)->returned_quantity);
        $this->assertEquals(1180.0, (float) $original->total);
        $this->assertEquals(1180.0, (float) $original->collected_amount);

        $writeOff = OrderAdjustment::query()
            ->where('order_id', $original->id)
            ->where('source', 'partial_return_writeoff')
            ->first();
        $this->assertNull($writeOff);
    }

    #[Test]
    public function unlinked_exchange_still_flags_hr_on_the_replacement(): void
    {
        $this->actingAs($this->adminUser());
        $this->mockCustomerLookup();
        $product = $this->product();

        Livewire::test(AdminOrderForm::class)
            ->set('phone', '01710000011')
            ->set('name', 'Walk-in')
            ->set('address', 'Dhaka')
            ->set('autoDelivery', false)
            ->set('deliveryCharge', '0')
            ->set('isExchange', true)
            ->set('lines', [
                $product->id => [
                    'product_id' => $product->id,
                    'name' => $product->name,
                    'quantity' => 1,
                    'price' => 0.0,
                    'purchase_price' => 400.0,
                    'unit_cost' => 400.0,
                    'line_total' => 0.0,
                    'product_image' => null,
                    'stock_quantity' => 20,
                ],
            ])
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $replacement = Order::query()->first();
        $this->assertTrue($replacement->is_replacement);
        $this->assertNull($replacement->exchange_of_order_id);
        $this->assertTrue($replacement->has_return);
    }

    #[Test]
    public function repeat_as_exchange_defaults_to_the_repeated_order(): void
    {
        $this->actingAs($this->adminUser());
        $this->mockCustomerLookup();
        [$original] = $this->deliveredOriginal();

        Livewire::test(AdminOrderForm::class, ['repeat' => $original->id])
            ->assertSet('isExchange', false)
            ->assertSet('exchangeOfOrderId', null)
            ->set('isExchange', true)
            ->assertSet('exchangeOfOrderId', $original->id)
            ->assertSet('exchangeOfOrderNumber', (string) $original->order_number);
    }

    #[Test]
    public function linking_exchange_flags_hr_without_rewriting_original_bill(): void
    {
        $this->actingAs($this->adminUser());
        $product = $this->product();
        [$original, $item] = $this->deliveredOriginal($product, quantity: 2);

        $replacement = Order::query()->create([
            'order_number' => 'EXC-'.uniqid(),
            'name' => 'Original Customer',
            'phone' => '01710000011',
            'address' => '[EXCHANGE PARCEL] Dhaka',
            'status' => 'new',
            'subtotal' => 0,
            'delivery_charge' => 0,
            'total' => 0,
            'is_replacement' => true,
            'exchange_of_order_id' => $original->id,
            'has_return' => false,
            'placed_at' => now(),
        ]);
        OrderProduct::query()->create([
            'order_id' => $replacement->id,
            'product_id' => $product->id,
            'name' => $product->name,
            'quantity' => 1,
            'returned_quantity' => 0,
            'price' => 0,
            'purchase_price' => 400,
            'unit_cost' => 400,
            'line_total' => 0,
        ]);

        app(OrderDeliveryReturnService::class)->settleOriginalForExchange(
            $original->fresh(['items']),
            $replacement->fresh(['items']),
        );

        $original->refresh()->load('items');
        $this->assertSame(0, (int) $original->items->firstWhere('id', $item->id)->returned_quantity);
        $this->assertTrue($original->has_return);
        $this->assertEquals(2280.0, (float) $original->total);
    }

    #[Test]
    public function unmatched_products_still_keep_original_bill_intact(): void
    {
        $this->actingAs($this->adminUser());
        [$original, $item] = $this->deliveredOriginal();
        $other = $this->product('Different Dress', 900);

        $replacement = Order::query()->create([
            'order_number' => 'EXC-'.uniqid(),
            'name' => 'Original Customer',
            'phone' => '01710000011',
            'address' => '[EXCHANGE PARCEL] Dhaka',
            'status' => 'new',
            'subtotal' => 0,
            'total' => 0,
            'is_replacement' => true,
            'exchange_of_order_id' => $original->id,
            'placed_at' => now(),
        ]);
        OrderProduct::query()->create([
            'order_id' => $replacement->id,
            'product_id' => $other->id,
            'name' => $other->name,
            'quantity' => 1,
            'price' => 0,
            'purchase_price' => 400,
            'unit_cost' => 400,
            'line_total' => 0,
        ]);

        app(OrderDeliveryReturnService::class)->settleOriginalForExchange(
            $original->fresh(['items']),
            $replacement->fresh(['items']),
        );

        $original->refresh()->load('items');
        $this->assertSame(0, (int) $original->items->firstWhere('id', $item->id)->returned_quantity);
        $this->assertEquals(1180.0, (float) $original->total);
        $this->assertTrue($original->has_return);
    }

    #[Test]
    public function relinking_the_same_original_does_not_create_a_write_off(): void
    {
        $this->actingAs($this->adminUser());
        $this->mockCustomerLookup();
        [$original, , $product] = $this->deliveredOriginal();

        Livewire::test(AdminOrderForm::class)
            ->set('phone', '01710000011')
            ->set('name', 'Original Customer')
            ->set('address', 'Dhaka')
            ->set('autoDelivery', false)
            ->set('deliveryCharge', '0')
            ->set('isExchange', true)
            ->call('selectExchangeOf', $original->id)
            ->set('lines', [
                $product->id => [
                    'product_id' => $product->id,
                    'name' => $product->name,
                    'quantity' => 1,
                    'price' => 0.0,
                    'purchase_price' => 400.0,
                    'unit_cost' => 400.0,
                    'line_total' => 0.0,
                    'product_image' => null,
                    'stock_quantity' => 20,
                ],
            ])
            ->call('save')
            ->assertHasNoErrors();

        $replacement = Order::query()->where('is_replacement', true)->first();
        $this->assertNotNull($replacement);

        Livewire::test(AdminOrderForm::class, ['order' => $replacement])
            ->assertSet('isExchange', true)
            ->assertSet('exchangeOfOrderId', $original->id)
            ->call('save')
            ->assertHasNoErrors();

        $writeOffs = OrderAdjustment::query()
            ->where('order_id', $original->id)
            ->where('source', 'partial_return_writeoff')
            ->get();
        $this->assertCount(0, $writeOffs);
        $this->assertEquals(1180.0, (float) $original->fresh()->total);
    }

    #[Test]
    public function admin_list_and_show_render_exchange_links(): void
    {
        $this->actingAs($this->adminUser());
        [$original] = $this->deliveredOriginal();

        $replacement = Order::query()->create([
            'order_number' => 'EXC-LIST',
            'name' => 'Original Customer',
            'phone' => '01710000011',
            'address' => '[EXCHANGE PARCEL] Dhaka',
            'status' => 'new',
            'subtotal' => 0,
            'total' => 0,
            'is_replacement' => true,
            'exchange_of_order_id' => $original->id,
            'placed_at' => now(),
        ]);

        Livewire::test(AdminOrders::class, ['segment' => 'new'])
            ->assertSee('Exchange of #'.$original->order_number)
            ->assertSeeHtml(route('admin.orders.show', $original));

        $this->get(route('admin.orders.show', $replacement))
            ->assertOk()
            ->assertSee('Exchange of #'.$original->order_number);

        $this->get(route('admin.orders.show', $original))
            ->assertOk()
            ->assertSee('Replaced by')
            ->assertSee('#EXC-LIST');
    }

    #[Test]
    public function exchange_cannot_link_to_itself(): void
    {
        $this->actingAs($this->adminUser());
        $this->mockCustomerLookup();
        [$original] = $this->deliveredOriginal();

        $replacement = Order::query()->create([
            'order_number' => 'EXC-SELF',
            'name' => 'Original Customer',
            'phone' => '01710000011',
            'address' => '[EXCHANGE PARCEL] Dhaka',
            'status' => 'new',
            'subtotal' => 0,
            'total' => 0,
            'is_replacement' => true,
            'exchange_of_order_id' => $original->id,
            'placed_at' => now(),
        ]);

        Livewire::test(AdminOrderForm::class, ['order' => $replacement])
            ->set('isExchange', true)
            ->set('exchangeOfOrderId', $replacement->id)
            ->call('save')
            ->assertHasErrors(['exchangeOfOrderId']);
    }

    #[Test]
    public function cancelled_original_is_not_settled(): void
    {
        $this->actingAs($this->adminUser());
        [$original, $item, $product] = $this->deliveredOriginal();
        $original->update(['status' => 'cancelled']);

        $replacement = Order::query()->create([
            'order_number' => 'EXC-CAN',
            'name' => 'Original Customer',
            'phone' => '01710000011',
            'address' => '[EXCHANGE PARCEL] Dhaka',
            'status' => 'new',
            'subtotal' => 0,
            'total' => 0,
            'is_replacement' => true,
            'exchange_of_order_id' => $original->id,
            'placed_at' => now(),
        ]);
        OrderProduct::query()->create([
            'order_id' => $replacement->id,
            'product_id' => $product->id,
            'name' => $product->name,
            'quantity' => 1,
            'price' => 0,
            'purchase_price' => 400,
            'unit_cost' => 400,
            'line_total' => 0,
        ]);

        app(OrderDeliveryReturnService::class)->settleOriginalForExchange(
            $original->fresh(['items']),
            $replacement->fresh(['items']),
        );

        $original->refresh()->load('items');
        $this->assertSame(0, (int) $original->items->firstWhere('id', $item->id)->returned_quantity);
        $this->assertFalse($original->has_return);
    }
}
