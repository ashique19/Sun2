<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderPlacedByLabelTest extends TestCase
{
    use RefreshDatabase;

    private function baseOrder(array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'order_number' => '26828',
            'name' => 'test account',
            'phone' => '01627237432',
            'address' => 'Test',
            'city' => 'Dhaka',
            'subtotal' => 970,
            'delivery_charge' => 0,
            'discount' => 0,
            'total' => 970,
            'cod_amount' => 970,
            'due_amount' => 970,
            'payment_status' => 'unpaid',
            'payment_method' => 'cod',
            'status' => 'new',
            'placed_at' => now(),
            'placed_via' => Order::PLACED_VIA_STOREFRONT,
        ], $overrides));
    }

    public function test_label_uses_placer_name_when_present(): void
    {
        $creator = User::factory()->create(['name' => 'md ashiqul islam']);

        $order = $this->baseOrder([
            'order_number' => '26827',
            'created_by' => $creator->id,
            'placed_via' => Order::PLACED_VIA_ADMIN,
            'name' => 'Dr Reshat Rumman',
        ]);

        $this->assertSame('md ashiqul islam', $order->placedByLabel());
    }

    public function test_guest_storefront_order_labels_as_customer(): void
    {
        $order = $this->baseOrder([
            'created_by' => null,
            'placed_via' => Order::PLACED_VIA_STOREFRONT,
        ]);

        $this->assertSame('Customer', $order->placedByLabel());
    }

    public function test_logged_in_customer_storefront_order_uses_customer_name(): void
    {
        $customer = User::factory()->create(['name' => 'test account']);

        $order = $this->baseOrder([
            'user_id' => $customer->id,
            'created_by' => $customer->id,
            'placed_via' => Order::PLACED_VIA_STOREFRONT,
            'name' => 'test account',
        ]);

        $this->assertSame('test account', $order->placedByLabel());
    }

    public function test_reseller_order_uses_reseller_name(): void
    {
        $reseller = User::factory()->create(['name' => 'Reseller One']);

        $order = $this->baseOrder([
            'reseller_id' => $reseller->id,
            'created_by' => $reseller->id,
            'placed_via' => Order::PLACED_VIA_RESELLER,
        ]);

        $this->assertSame('Reseller One', $order->placedByLabel());
    }
}
