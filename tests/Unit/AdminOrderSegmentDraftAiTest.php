<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Support\AdminOrderSegment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminOrderSegmentDraftAiTest extends TestCase
{
    use RefreshDatabase;

    private function order(string $status, string $number, float $total = 100): Order
    {
        return Order::query()->create([
            'order_number' => $number,
            'name' => 'N',
            'phone' => '01627237432',
            'address' => 'A',
            'subtotal' => $total,
            'delivery_charge' => 0,
            'discount' => 0,
            'total' => $total,
            'cod_amount' => $total,
            'due_amount' => $total,
            'payment_status' => 'unpaid',
            'payment_method' => 'cod',
            'status' => $status,
            'placed_at' => now(),
            'placed_via' => Order::PLACED_VIA_ADMIN,
        ]);
    }

    public function test_counts_include_draft_ai_and_exclude_from_all(): void
    {
        $this->order('new', '1');
        $this->order('confirmed', '2');
        $this->order(Order::STATUS_DRAFT, '3');
        $this->order(Order::STATUS_DRAFT, '4');
        $this->order('dispatched', '5');

        $counts = AdminOrderSegment::counts(fresh: true);

        $this->assertSame(2, $counts['new']);
        $this->assertSame(2, $counts['draft-ai']);
        $this->assertSame(1, $counts['dispatched']);
        $this->assertSame(3, $counts['all']);
        $this->assertSame('By AI', AdminOrderSegment::label('draft-ai'));
        $this->assertTrue(AdminOrderSegment::isValid('draft-ai'));
    }

    public function test_values_sum_totals_for_new_and_dispatched(): void
    {
        $this->order('new', '1', 500);
        $this->order('confirmed', '2', 700);
        $this->order('dispatched', '3', 1200);
        $this->order('dispatched', '4', 300);
        $this->order('delivered', '5', 9999);
        $this->order(Order::STATUS_DRAFT, '6', 50);

        $values = AdminOrderSegment::values(fresh: true);

        $this->assertSame(1200.0, $values['new']);
        $this->assertSame(1500.0, $values['dispatched']);
        $this->assertArrayNotHasKey('delivered', $values);
        $this->assertArrayNotHasKey('draft-ai', $values);
    }
}
