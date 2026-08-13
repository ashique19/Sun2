<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Services\Orders\OrderAdjustmentSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrderAdjustmentSyncTransactionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function replace_adjustments_rolls_back_with_outer_transaction(): void
    {
        $order = Order::query()->create([
            'order_number' => 'ADJ-RB-'.uniqid(),
            'name' => 'Customer',
            'phone' => '01710000002',
            'address' => 'Dhaka',
            'status' => 'new',
            'subtotal' => 1000,
            'delivery_charge' => 80,
            'total' => 1080,
            'due_amount' => 1080,
            'placed_via' => Order::PLACED_VIA_ADMIN,
        ]);

        try {
            DB::transaction(function () use ($order): void {
                app(OrderAdjustmentSync::class)->replaceAdjustments($order, [
                    [
                        'type' => 'discount',
                        'label' => 'Promo',
                        'amount' => 50,
                        'source' => 'admin',
                    ],
                ]);

                throw new \RuntimeException('force rollback');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(0, $order->fresh()->adjustments()->count());
        $this->assertSame(0.0, (float) $order->fresh()->discount);
    }

    #[Test]
    public function replace_adjustments_still_wraps_when_called_standalone(): void
    {
        $order = Order::query()->create([
            'order_number' => 'ADJ2-'.uniqid(),
            'name' => 'Customer',
            'phone' => '01710000001',
            'address' => 'Dhaka',
            'status' => 'new',
            'subtotal' => 1000,
            'delivery_charge' => 80,
            'total' => 1080,
            'due_amount' => 1080,
            'placed_via' => Order::PLACED_VIA_ADMIN,
        ]);

        app(OrderAdjustmentSync::class)->replaceAdjustments($order, [
            [
                'type' => 'charge',
                'label' => 'Gift wrap',
                'amount' => 20,
                'source' => 'admin',
            ],
        ]);

        $this->assertSame(1, $order->fresh()->adjustments()->count());
        $this->assertSame(20.0, (float) $order->fresh()->charge);
    }
}
