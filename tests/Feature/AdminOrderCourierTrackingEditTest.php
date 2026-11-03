<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminOrderShow;
use App\Models\Courier;
use App\Models\Order;
use App\Models\User;
use App\Services\Admin\OrderDispatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminOrderCourierTrackingEditTest extends TestCase
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
        return Courier::query()->firstOrCreate(
            ['slug' => 'steadfast'],
            [
                'name' => 'Steadfast',
                'charge' => 60,
                'osd_charge' => 110,
                'cod_percentage' => 1,
                'balance' => 0,
                'is_active' => true,
                'is_default' => true,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function dispatchedOrder(array $overrides = []): Order
    {
        $courier = $this->steadfast();

        return Order::query()->create(array_merge([
            'order_number' => 'TRK-EDIT-'.uniqid(),
            'name' => 'Tracked Customer',
            'phone' => '01710000099',
            'address' => 'Dhaka',
            'city' => 'Dhaka',
            'status' => 'dispatched',
            'subtotal' => 1000,
            'delivery_charge' => 80,
            'courier_charge' => 60,
            'total' => 1080,
            'cod_amount' => 1080,
            'due_amount' => 1080,
            'payment_status' => 'unpaid',
            'payment_method' => 'cod',
            'courier_id' => $courier->id,
            'courier_tracker' => 'SFR_WRONG',
            'courier_consignment_id' => '111',
            'dispatch_date' => now()->subHour(),
            'placed_at' => now()->subDay(),
            'placed_via' => Order::PLACED_VIA_ADMIN,
        ], $overrides));
    }

    #[Test]
    public function order_show_prefills_and_saves_tracking_without_changing_status(): void
    {
        $this->actingAs($this->adminUser());
        $order = $this->dispatchedOrder();

        Livewire::test(AdminOrderShow::class, ['order' => $order])
            ->assertSet('editTracker', 'SFR_WRONG')
            ->assertSet('editConsignmentId', '111')
            ->assertSee('Fix tracking')
            ->assertSee('Save tracking')
            ->set('editTracker', '15A4B80AA')
            ->set('editConsignmentId', '277193413')
            ->call('saveCourierTracking')
            ->assertHasNoErrors()
            ->assertSee('Tracking saved.')
            ->assertSet('editTracker', '15A4B80AA')
            ->assertSeeHtml('href="https://steadfast.com.bd/user/consignment/277193413"')
            ->assertSee('15A4B80AA');

        $fresh = $order->fresh();
        $this->assertSame('dispatched', $fresh->status);
        $this->assertSame('15A4B80AA', $fresh->courier_tracker);
        $this->assertSame('277193413', $fresh->courier_consignment_id);
        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->id,
            'status' => 'dispatched',
            'note' => 'Tracking updated. SFR_WRONG → 15A4B80AA. Parcel ID updated. 111 → 277193413.',
        ]);
    }

    #[Test]
    public function empty_tracking_code_is_rejected(): void
    {
        $this->actingAs($this->adminUser());
        $order = $this->dispatchedOrder();

        Livewire::test(AdminOrderShow::class, ['order' => $order])
            ->set('editTracker', '   ')
            ->call('saveCourierTracking')
            ->assertHasErrors(['editTracker']);

        $this->assertSame('SFR_WRONG', $order->fresh()->courier_tracker);
    }

    #[Test]
    public function dispatched_order_without_tracker_can_add_one(): void
    {
        $this->actingAs($this->adminUser());
        $order = $this->dispatchedOrder([
            'courier_tracker' => null,
            'courier_consignment_id' => null,
        ]);

        Livewire::test(AdminOrderShow::class, ['order' => $order])
            ->assertSee('Fix tracking')
            ->set('editTracker', '15A4B80AA')
            ->set('editConsignmentId', '277193413')
            ->call('saveCourierTracking')
            ->assertHasNoErrors()
            ->assertSee('Tracking saved.');

        $fresh = $order->fresh();
        $this->assertSame('dispatched', $fresh->status);
        $this->assertSame('15A4B80AA', $fresh->courier_tracker);
        $this->assertSame('277193413', $fresh->courier_consignment_id);
    }

    #[Test]
    public function update_tracking_does_not_recredit_courier_balance(): void
    {
        $this->actingAs($this->adminUser());
        $courier = $this->steadfast();
        $courier->update(['balance' => 0]);
        $order = $this->dispatchedOrder(['courier_id' => $courier->id]);

        app(OrderDispatchService::class)->updateTracking($order, '15A4B80AA', '277193413');

        $this->assertSame(0.0, (float) $courier->fresh()->balance);
        $this->assertSame('dispatched', $order->fresh()->status);
    }
}
