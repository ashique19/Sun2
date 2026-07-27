<?php

namespace Tests\Feature;

use App\Models\AdminAttentionItem;
use App\Models\Courier;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SteadfastWebhookAttentionTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function order(array $overrides = []): Order
    {
        Courier::query()->firstOrCreate(['slug' => 'steadfast'], ['name' => 'Steadfast']);

        return Order::query()->create(array_merge([
            'order_number' => 'ORD-1001',
            'name' => 'Customer',
            'phone' => '01700000000',
            'address' => 'Dhaka',
            'status' => 'dispatched',
            'subtotal' => 1000,
            'total' => 1200,
            'due_amount' => 1200,
            'cod_amount' => 1200,
            'placed_via' => Order::PLACED_VIA_ADMIN,
            'courier_tracker' => 'SFR_123',
            'is_replacement' => false,
            'has_return' => false,
        ], $overrides));
    }

    #[Test]
    public function delivery_status_mismatch_creates_one_reusable_attention_item(): void
    {
        config([
            'steadfast.webhook.enabled' => true,
            'steadfast.webhook.token' => 'secret-token',
        ]);

        $order = $this->order();

        $payload = [
            'notification_type' => 'delivery_status',
            'invoice' => $order->order_number,
            'tracking_id' => $order->courier_tracker,
            'status' => 'partial_delivered',
            'cod_amount' => 700,
            'updated_at' => now()->toDateTimeString(),
            'tracking_message' => 'Partial delivered',
        ];

        $headers = ['Authorization' => 'Bearer secret-token'];

        $this->postJson('/api/steadfast/webhook', $payload, $headers)->assertOk();
        $this->postJson('/api/steadfast/webhook', $payload, $headers)->assertOk();

        $this->assertSame(1, AdminAttentionItem::query()->count());
        $this->assertSame('dispatched', $order->fresh()->status);
    }

    #[Test]
    public function tracking_update_delivered_with_cod_mismatch_creates_attention_and_blocks_delivery(): void
    {
        config([
            'steadfast.webhook.enabled' => true,
            'steadfast.webhook.token' => 'secret-token',
        ]);

        $order = $this->order([
            'order_number' => 'ORD-1002',
            'courier_tracker' => 'SFR_456',
        ]);

        $payload = [
            'notification_type' => 'tracking_update',
            'invoice' => $order->order_number,
            'tracking_id' => $order->courier_tracker,
            'cod_amount' => 600,
            'updated_at' => now()->toDateTimeString(),
            'tracking_message' => 'Parcel delivered successfully',
        ];

        $this->postJson('/api/steadfast/webhook', $payload, [
            'Authorization' => 'Bearer secret-token',
        ])->assertOk();

        $item = AdminAttentionItem::query()->sole();

        $this->assertSame(AdminAttentionItem::ISSUE_TYPE_COD_MISMATCH, $item->issue_type);
        $this->assertSame('dispatched', $order->fresh()->status);
    }

    #[Test]
    public function tracking_update_with_matching_cod_marks_delivered(): void
    {
        config([
            'steadfast.webhook.enabled' => true,
            'steadfast.webhook.token' => 'secret-token',
        ]);

        $order = $this->order([
            'order_number' => 'ORD-1003',
            'courier_tracker' => 'SFR_789',
        ]);

        $payload = [
            'notification_type' => 'tracking_update',
            'invoice' => $order->order_number,
            'tracking_id' => $order->courier_tracker,
            'cod_amount' => 1200,
            'updated_at' => now()->toDateTimeString(),
            'tracking_message' => 'Parcel delivered successfully',
        ];

        $this->postJson('/api/steadfast/webhook', $payload, [
            'Authorization' => 'Bearer secret-token',
        ])->assertOk();

        $this->assertSame('delivered', $order->fresh()->status);
        $this->assertSame(0, AdminAttentionItem::query()->count());
    }
}
