<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderCreatedByLabelTest extends TestCase
{
    use RefreshDatabase;

    public function test_label_uses_creator_name_when_present(): void
    {
        $creator = User::factory()->create(['name' => 'md ashiqul islam']);

        $order = Order::query()->create([
            'order_number' => '26827',
            'created_by' => $creator->id,
            'name' => 'Dr Reshat Rumman',
            'phone' => '01627237432',
            'address' => 'Test',
            'city' => 'Dhaka',
            'subtotal' => 100,
            'delivery_charge' => 0,
            'discount' => 0,
            'total' => 100,
            'cod_amount' => 100,
            'due_amount' => 100,
            'payment_status' => 'unpaid',
            'payment_method' => 'cod',
            'status' => 'new',
            'placed_at' => now(),
        ]);

        $this->assertSame('md ashiqul islam', $order->createdByLabel());
    }

    public function test_label_falls_back_to_customer_when_creator_missing(): void
    {
        $order = Order::query()->create([
            'order_number' => '26828',
            'created_by' => null,
            'name' => 'test account',
            'phone' => '01627237432',
            'address' => 'Test',
            'city' => 'Rangamati',
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
        ]);

        $this->assertSame('Customer', $order->createdByLabel());
    }
}
