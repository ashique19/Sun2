<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminOrders;
use App\Livewire\Admin\AdminOrderShow;
use App\Models\Courier;
use App\Models\CourierData;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminOrdersCourierLiveAjaxTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function configureSteadfast(): void
    {
        config([
            'steadfast.api_key' => 'test-key',
            'steadfast.secret_key' => 'test-secret',
            'steadfast.base_url' => 'https://portal.packzy.com/api/v1',
            'steadfast.status_timeout' => 4,
        ]);
    }

    private function steadfast(): Courier
    {
        return Courier::query()->create([
            'name' => 'Steadfast',
            'slug' => 'steadfast',
            'charge' => 60,
            'osd_charge' => 110,
            'cod_percentage' => 1,
            'balance' => 0,
            'is_active' => true,
            'is_default' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function dispatchedOrder(Courier $courier, array $overrides = []): Order
    {
        $order = Order::query()->create(array_merge([
            'order_number' => 'DSP-'.uniqid(),
            'name' => 'Dispatched Customer',
            'phone' => '01710000077',
            'address' => 'Dhaka',
            'city' => 'Dhaka',
            'status' => 'dispatched',
            'subtotal' => 1000,
            'delivery_charge' => 80,
            'courier_charge' => 60,
            'total' => 1080,
            'cod_amount' => 1080,
            'due_amount' => 1080,
            'collected_amount' => 0,
            'payment_status' => 'unpaid',
            'payment_method' => 'cod',
            'courier_id' => $courier->id,
            'courier_tracker' => 'SF112233',
            'courier_consignment_id' => '288112233',
            'dispatch_date' => now()->subDay(),
            'placed_at' => now()->subDays(2),
            'placed_via' => Order::PLACED_VIA_STOREFRONT,
        ], $overrides));

        CourierData::query()->create([
            'order_id' => $order->id,
            'courier_id' => $courier->id,
            'api_data' => [
                'notification_type' => 'delivery_status',
                'status' => 'in_review',
                'delivery_status' => 'in_review',
                'tracking_message' => 'Parcel is in review',
                'invoice' => $order->order_number,
            ],
            'created_at' => now()->subHours(6),
        ]);

        return $order;
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function postDispatchSegmentsProvider(): array
    {
        return [
            'dispatched' => ['dispatched', [
                'status' => 'dispatched',
                'name' => 'Dispatched Ajax Customer',
            ]],
            'delivered' => ['delivered', [
                'status' => 'delivered',
                'name' => 'Delivered Ajax Customer',
                'collected_amount' => 1080,
                'due_amount' => 0,
                'payment_status' => 'paid',
                'actual_delivery_date' => now()->subDay(),
            ]],
            'cancelled' => ['cancel-return', [
                'status' => 'cancelled',
                'name' => 'Cancelled Ajax Customer',
                'collected_amount' => 0,
                'due_amount' => 0,
            ]],
            'returned' => ['cancel-return', [
                'status' => 'returned',
                'name' => 'Returned Ajax Customer',
                'collected_amount' => 0,
                'due_amount' => 0,
            ]],
            'return-pending' => ['return-pending', [
                'status' => 'delivered',
                'name' => 'Return Pending Ajax Customer',
                'has_return' => true,
                'collected_amount' => 1080,
                'due_amount' => 0,
                'payment_status' => 'paid',
                'actual_delivery_date' => now()->subDay(),
            ]],
        ];
    }

    #[Test]
    #[DataProvider('postDispatchSegmentsProvider')]
    public function post_dispatch_lists_paint_without_calling_courier_api(string $segment, array $overrides): void
    {
        $this->configureSteadfast();
        Http::fake(fn () => throw new \RuntimeException('Courier API must not run during first paint.'));

        $this->actingAs($this->adminUser());
        $order = $this->dispatchedOrder($this->steadfast(), $overrides);

        Livewire::test(AdminOrders::class, ['segment' => $segment])
            ->assertSet('segment', $segment)
            ->assertSee($overrides['name'])
            ->assertSee('SF112233')
            ->assertSee('Parcel is in review')
            ->assertSeeHtml('data-admin-courier-live-root')
            ->assertSeeHtml('data-admin-courier-live-spinner')
            ->assertSeeHtml('aria-busy="true"')
            ->assertSeeHtml('wire:click="clearCourierLiveStatuses"')
            ->assertDontSeeHtml('wire:click="refreshCourierStatuses"')
            ->assertDontSeeHtml('$wire.refreshCourierStatuses');

        Http::assertNothingSent();
        $this->assertNotSame('new', $segment);
        $this->assertGreaterThan(0, $order->id);
    }

    #[Test]
    public function live_courier_status_endpoint_returns_faked_status(): void
    {
        $this->configureSteadfast();
        Http::fake([
            'portal.packzy.com/api/v1/status_by_invoice/*' => Http::response([
                'delivery_status' => 'delivered',
            ], 200),
        ]);

        $this->actingAs($this->adminUser());
        $order = $this->dispatchedOrder($this->steadfast());

        $this->postJson(route('admin.orders.live-courier-status'), [
            'order_ids' => [$order->id],
        ])
            ->assertOk()
            ->assertJsonPath('statuses.'.$order->id, 'delivered');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/status_by_invoice/'));
    }

    #[Test]
    public function live_courier_status_endpoint_requires_staff_admin(): void
    {
        $this->configureSteadfast();

        $this->postJson(route('admin.orders.live-courier-status'), [
            'order_ids' => [1],
        ])->assertRedirect();

        Role::findOrCreate('moderator');
        $moderator = User::factory()->create();
        $moderator->assignRole('moderator');

        $this->actingAs($moderator)
            ->postJson(route('admin.orders.live-courier-status'), [
                'order_ids' => [1],
            ])
            ->assertForbidden();
    }

    #[Test]
    public function apply_live_statuses_updates_chip_without_http(): void
    {
        $this->configureSteadfast();
        Http::fake(fn () => throw new \RuntimeException('Apply must not call the courier API.'));

        $this->actingAs($this->adminUser());
        $order = $this->dispatchedOrder($this->steadfast());

        Livewire::test(AdminOrders::class, ['segment' => 'dispatched'])
            ->call('applyCourierLiveStatuses', [(string) $order->id => 'delivered'])
            ->assertSee('DELIVERED')
            ->assertDontSeeHtml('data-admin-courier-live-spinner');

        Http::assertNothingSent();
    }

    #[Test]
    public function settle_live_statuses_stops_spinner_and_keeps_stored_status(): void
    {
        $this->configureSteadfast();
        Http::fake();

        $this->actingAs($this->adminUser());
        $order = $this->dispatchedOrder($this->steadfast());

        Livewire::test(AdminOrders::class, ['segment' => 'dispatched'])
            ->assertSeeHtml('data-admin-courier-live-spinner')
            ->call('settleCourierLiveStatuses', [$order->id])
            ->assertDontSeeHtml('data-admin-courier-live-spinner')
            ->assertSee('IN_REVIEW');
    }

    #[Test]
    #[DataProvider('postDispatchSegmentsProvider')]
    public function order_show_paints_without_calling_courier_api(string $segment, array $overrides): void
    {
        $this->configureSteadfast();
        Http::fake(fn () => throw new \RuntimeException('Courier API must not run during first paint.'));

        $this->actingAs($this->adminUser());
        $order = $this->dispatchedOrder($this->steadfast(), $overrides);

        Livewire::test(AdminOrderShow::class, ['order' => $order])
            ->assertSee($overrides['name'])
            ->assertSee('SF112233')
            ->assertSee('Parcel is in review')
            ->assertSeeHtml('data-admin-courier-live-root')
            ->assertSeeHtml('data-admin-courier-live-spinner')
            ->assertDontSeeHtml('wire:click="refreshCourierStatus"');

        Http::assertNothingSent();
        $this->assertNotSame('', $segment);
    }

    #[Test]
    public function switching_to_delivered_and_cancel_return_starts_ajax_not_livewire_http(): void
    {
        $this->configureSteadfast();
        Http::fake(fn () => throw new \RuntimeException('Courier API must not run during list navigation.'));

        $this->actingAs($this->adminUser());
        $courier = $this->steadfast();
        $this->dispatchedOrder($courier, [
            'status' => 'delivered',
            'name' => 'Delivered Switch Customer',
            'collected_amount' => 1080,
            'due_amount' => 0,
            'payment_status' => 'paid',
            'actual_delivery_date' => now()->subDay(),
        ]);
        $this->dispatchedOrder($courier, [
            'status' => 'cancelled',
            'name' => 'Cancelled Switch Customer',
            'collected_amount' => 0,
            'due_amount' => 0,
            'courier_tracker' => 'SFCANCEL1',
            'courier_consignment_id' => '288000099',
        ]);

        Livewire::test(AdminOrders::class, ['segment' => 'dispatched'])
            ->call('switchSegment', 'delivered')
            ->assertSet('segment', 'delivered')
            ->assertSee('Delivered Switch Customer')
            ->assertSeeHtml('data-admin-courier-live-root')
            ->assertSeeHtml('data-admin-courier-live-spinner')
            ->call('switchSegment', 'cancel-return')
            ->assertSet('segment', 'cancel-return')
            ->assertSee('Cancelled Switch Customer')
            ->assertSeeHtml('data-admin-courier-live-root')
            ->assertSeeHtml('data-admin-courier-live-spinner');

        Http::assertNothingSent();
    }

    #[Test]
    public function live_endpoint_accepts_delivered_and_cancelled_orders(): void
    {
        $this->configureSteadfast();
        Http::fake([
            'portal.packzy.com/api/v1/status_by_invoice/*' => Http::response([
                'delivery_status' => 'cancelled',
            ], 200),
        ]);

        $this->actingAs($this->adminUser());
        $courier = $this->steadfast();
        $delivered = $this->dispatchedOrder($courier, [
            'status' => 'delivered',
            'name' => 'Delivered Endpoint Customer',
            'collected_amount' => 1080,
            'due_amount' => 0,
            'payment_status' => 'paid',
            'actual_delivery_date' => now()->subDay(),
        ]);
        $cancelled = $this->dispatchedOrder($courier, [
            'status' => 'cancelled',
            'name' => 'Cancelled Endpoint Customer',
            'collected_amount' => 0,
            'due_amount' => 0,
            'courier_tracker' => 'SFCANCEL2',
            'courier_consignment_id' => '288000088',
            'order_number' => 'CAN-'.uniqid(),
        ]);

        $this->postJson(route('admin.orders.live-courier-status'), [
            'order_ids' => [$delivered->id, $cancelled->id],
        ])
            ->assertOk()
            ->assertJsonPath('statuses.'.$delivered->id, 'cancelled')
            ->assertJsonPath('statuses.'.$cancelled->id, 'cancelled');
    }
}
