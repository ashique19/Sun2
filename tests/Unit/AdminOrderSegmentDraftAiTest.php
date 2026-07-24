<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Support\AdminOrderSegment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminOrderSegmentDraftAiTest extends TestCase
{
    use RefreshDatabase;

    private function order(string $status, string $number): Order
    {
        return Order::query()->create([
            'order_number' => $number,
            'name' => 'N',
            'phone' => '01627237432',
            'address' => 'A',
            'subtotal' => 100,
            'delivery_charge' => 0,
            'discount' => 0,
            'total' => 100,
            'cod_amount' => 100,
            'due_amount' => 100,
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
        $this->assertSame('Draft by AI', AdminOrderSegment::label('draft-ai'));
        $this->assertTrue(AdminOrderSegment::isValid('draft-ai'));
    }
}
