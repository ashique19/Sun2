<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminOrderForm;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Admin\CustomerLookupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminOrderFormValidationTest extends TestCase
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
            'name' => 'Test Kurti',
            'slug' => 'test-kurti',
            'sku' => 'TK001',
            'price' => 980,
            'purchase_price' => 400,
            'stock_quantity' => 10,
            'is_published' => true,
            'display_order' => 0,
        ]);
    }

    private function mockCustomerLookup(): void
    {
        $lookup = Mockery::mock(CustomerLookupService::class);
        $lookup->shouldReceive('lookup')->andReturn([
            'phone' => '01627237432',
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

    public function test_create_order_accepts_filled_phone_and_name(): void
    {
        $this->actingAs($this->adminUser());
        $this->mockCustomerLookup();

        $product = $this->product();

        Livewire::test(AdminOrderForm::class)
            ->set('phone', '01627237432')
            ->set('name', 'Dr farhana')
            ->set('address', '1 no road, 12 no bari, dhokkhin gaon, bashaboo')
            ->set('lines', [
                $product->id => [
                    'product_id' => $product->id,
                    'name' => $product->name,
                    'quantity' => 1,
                    'price' => 980.0,
                    'purchase_price' => 400.0,
                    'line_total' => 980.0,
                    'product_image' => null,
                    'stock_quantity' => 10,
                ],
            ])
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $order = Order::query()->first();
        $this->assertNotNull($order);
        $this->assertSame('Dr farhana', $order->name);
        $this->assertSame('01627237432', $order->phone);
    }

    public function test_create_order_allows_saving_without_products(): void
    {
        $this->actingAs($this->adminUser());
        $this->mockCustomerLookup();

        Livewire::test(AdminOrderForm::class)
            ->set('phone', '01627237432')
            ->set('name', 'Rush customer')
            ->set('address', 'Bashabo, Dhaka')
            ->set('lines', [])
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $order = Order::query()->first();
        $this->assertNotNull($order);
        $this->assertSame('Rush customer', $order->name);
        $this->assertSame('01627237432', $order->phone);
        $this->assertSame(0, $order->items()->count());
        $this->assertSame(0.0, (float) $order->subtotal);
    }

    public function test_create_order_requires_phone_and_name_when_empty(): void
    {
        $this->actingAs($this->adminUser());
        $this->mockCustomerLookup();

        $product = $this->product();

        Livewire::test(AdminOrderForm::class)
            ->set('phone', '')
            ->set('name', '')
            ->set('address', 'Some address')
            ->set('lines', [
                $product->id => [
                    'product_id' => $product->id,
                    'name' => $product->name,
                    'quantity' => 1,
                    'price' => 980.0,
                    'purchase_price' => 400.0,
                    'line_total' => 980.0,
                    'product_image' => null,
                    'stock_quantity' => 10,
                ],
            ])
            ->call('save')
            ->assertHasErrors(['phone', 'name']);
    }

    public function test_save_parses_pasted_customer_block_before_validation(): void
    {
        $this->actingAs($this->adminUser());
        $this->mockCustomerLookup();

        $product = $this->product();

        $paste = "Dr farhana\n01627237432\n1 no road, 12 no bari, dhokkhin gaon, bashaboo";

        // Setting phone runs updatedPhone → paste parse fills name/address.
        Livewire::test(AdminOrderForm::class)
            ->set('phone', $paste)
            ->assertSet('name', 'Dr farhana')
            ->assertSet('phone', '01627237432')
            ->set('lines', [
                $product->id => [
                    'product_id' => $product->id,
                    'name' => $product->name,
                    'quantity' => 1,
                    'price' => 980.0,
                    'purchase_price' => 400.0,
                    'line_total' => 980.0,
                    'product_image' => null,
                    'stock_quantity' => 10,
                ],
            ])
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $order = Order::query()->first();
        $this->assertNotNull($order);
        $this->assertSame('Dr farhana', $order->name);
        $this->assertSame('01627237432', $order->phone);
        $this->assertStringContainsString('bashaboo', $order->address);
    }

    public function test_normalize_parses_raw_paste_block_left_in_phone(): void
    {
        $this->mockCustomerLookup();

        $form = new AdminOrderForm;
        $form->phone = "Dr farhana\n01627237432\n1 no road, bashaboo";
        $form->name = '';
        $form->address = '';

        $method = new \ReflectionMethod(AdminOrderForm::class, 'normalizeCustomerFieldsForSave');
        $method->invoke($form);

        $this->assertSame('Dr farhana', $form->name);
        $this->assertSame('01627237432', $form->phone);
        $this->assertStringContainsString('bashaboo', $form->address);
    }
}
