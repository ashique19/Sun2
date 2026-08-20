<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminCouriers;
use App\Models\AdminAttentionItem;
use App\Models\Courier;
use App\Models\CourierBalanceEntry;
use App\Models\CourierData;
use App\Models\Order;
use App\Models\User;
use App\Services\Admin\CourierBalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminCouriersDiffOrdersTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function steadfast(): Courier
    {
        return Courier::query()->create([
            'name' => 'Steadfast',
            'slug' => 'steadfast',
            'charge' => 60,
            'osd_charge' => 110,
            'cod_percentage' => 1,
            'balance' => 1500,
            'is_active' => true,
            'is_default' => true,
        ]);
    }

    #[Test]
    public function mismatch_orders_includes_cod_attention_and_webhook_collected_diff(): void
    {
        $courier = $this->steadfast();

        $mismatch = Order::query()->create([
            'order_number' => 'DIFF-ATTN',
            'name' => 'Mismatch Customer',
            'phone' => '01710000030',
            'address' => 'Dhaka',
            'status' => 'dispatched',
            'subtotal' => 1200,
            'total' => 1200,
            'cod_amount' => 1200,
            'courier_id' => $courier->id,
            'dispatch_date' => now(),
            'placed_at' => now(),
        ]);

        AdminAttentionItem::query()->create([
            'order_id' => $mismatch->id,
            'issue_type' => AdminAttentionItem::ISSUE_TYPE_COD_MISMATCH,
            'title' => 'COD Mismatch - Order #DIFF-ATTN',
            'description' => 'Expected 1200 collected 800',
            'data' => [
                'expected_amount' => 1200,
                'collected_amount' => 800,
                'steadfast_status' => 'partial_delivered',
            ],
        ]);

        $webhookDiff = Order::query()->create([
            'order_number' => 'DIFF-WH',
            'name' => 'Webhook Diff Customer',
            'phone' => '01710000031',
            'address' => 'Dhaka',
            'status' => 'dispatched',
            'subtotal' => 1000,
            'total' => 1000,
            'cod_amount' => 1000,
            'courier_id' => $courier->id,
            'dispatch_date' => now(),
            'placed_at' => now(),
        ]);

        CourierData::query()->create([
            'order_id' => $webhookDiff->id,
            'courier_id' => $courier->id,
            'api_data' => [
                'notification_type' => 'delivery_status',
                'collected_amount' => 700,
                'tracking_message' => 'Partial collection reported',
                'status' => 'partial_delivered',
            ],
            'created_at' => now(),
        ]);

        $returned = Order::query()->create([
            'order_number' => 'DIFF-RET',
            'name' => 'Returned Customer',
            'phone' => '01710000032',
            'address' => 'Dhaka',
            'status' => 'returned',
            'subtotal' => 900,
            'total' => 900,
            'cod_amount' => 900,
            'has_return' => true,
            'courier_id' => $courier->id,
            'placed_at' => now()->subDay(),
        ]);

        CourierBalanceEntry::query()->create([
            'courier_id' => $courier->id,
            'type' => 'dispatch',
            'amount' => 900,
            'balance_after' => 900,
            'order_id' => $returned->id,
            'note' => 'Dispatch #DIFF-RET',
        ]);

        $rows = app(CourierBalanceService::class)->mismatchOrders($courier);

        $this->assertNotEmpty($rows);
        $this->assertTrue(collect($rows)->contains(fn (array $row) => $row['order_number'] === 'DIFF-ATTN'));
        $this->assertTrue(collect($rows)->contains(fn (array $row) => $row['order_number'] === 'DIFF-WH'));
        $this->assertTrue(collect($rows)->contains(fn (array $row) => $row['order_number'] === 'DIFF-RET'));

        $attn = collect($rows)->firstWhere('order_number', 'DIFF-ATTN');
        $this->assertSame('cod_mismatch', $attn['reason']);
        $this->assertSame(800.0, $attn['courier_collected']);
        $this->assertSame(-400.0, $attn['delta']);
    }

    #[Test]
    public function diff_text_opens_modal_with_mismatch_orders_when_nonzero(): void
    {
        config([
            'steadfast.api_key' => 'test-key',
            'steadfast.secret_key' => 'test-secret',
            'steadfast.base_url' => 'https://portal.packzy.com/api/v1',
        ]);

        Http::fake([
            'portal.packzy.com/api/v1/get_balance' => Http::response(['current_balance' => 700], 200),
        ]);

        $this->actingAs($this->adminUser());
        $courier = $this->steadfast();

        Order::query()->create([
            'order_number' => 'PEND-DIFF',
            'name' => 'Pending Customer',
            'phone' => '01710000033',
            'address' => 'Dhaka',
            'status' => 'dispatched',
            'subtotal' => 500,
            'total' => 500,
            'cod_amount' => 500,
            'courier_id' => $courier->id,
            'dispatch_date' => now(),
            'placed_at' => now(),
        ]);

        $mismatch = Order::query()->create([
            'order_number' => 'MODAL-ATTN',
            'name' => 'Modal Customer',
            'phone' => '01710000034',
            'address' => 'Dhaka',
            'status' => 'dispatched',
            'subtotal' => 1200,
            'total' => 1200,
            'cod_amount' => 1200,
            'courier_id' => $courier->id,
            'dispatch_date' => now(),
            'placed_at' => now(),
        ]);

        AdminAttentionItem::query()->create([
            'order_id' => $mismatch->id,
            'issue_type' => AdminAttentionItem::ISSUE_TYPE_COD_MISMATCH,
            'title' => 'COD Mismatch',
            'description' => 'Expected 1200 collected 800',
            'data' => [
                'expected_amount' => 1200,
                'collected_amount' => 800,
                'steadfast_status' => 'partial_delivered',
            ],
        ]);

        // No settled receivable yet; expected_api 0 vs API 700 → nonzero diff opens modal
        Livewire::test(AdminCouriers::class)
            ->call('loadApiBalances')
            ->assertSeeHtml('wire:click="openDiffOrders('.$courier->id.')"')
            ->assertSeeHtml('Diff')
            ->call('openDiffOrders', $courier->id)
            ->assertSet('showDiffModal', true)
            ->assertSee('Balance Diff — Steadfast')
            ->assertSee('#MODAL-ATTN')
            ->assertSee('Modal Customer')
            ->assertSee('COD mismatch')
            ->assertSee('800');
    }

    #[Test]
    public function matched_diff_is_not_clickable(): void
    {
        config([
            'steadfast.api_key' => 'test-key',
            'steadfast.secret_key' => 'test-secret',
            'steadfast.base_url' => 'https://portal.packzy.com/api/v1',
        ]);

        Http::fake([
            'portal.packzy.com/api/v1/get_balance' => Http::response(['current_balance' => 0], 200),
        ]);

        $this->actingAs($this->adminUser());
        $courier = $this->steadfast();

        // No delivered cash received → expected_api 0; API 0 → Diff 0
        Livewire::test(AdminCouriers::class)
            ->call('loadApiBalances')
            ->assertDontSeeHtml('wire:click="openDiffOrders('.$courier->id.')"')
            ->assertSeeHtml('Diff');
    }
}
