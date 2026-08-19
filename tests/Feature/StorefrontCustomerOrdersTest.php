<?php

namespace Tests\Feature;

use App\Livewire\StorefrontAccount;
use App\Livewire\StorefrontOrderDetail;
use App\Models\Courier;
use App\Models\CourierData;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StorefrontCustomerOrdersTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): User
    {
        return User::factory()->create([
            'name' => 'Customer',
            'phone' => '01627237432',
        ]);
    }

    private function orderFor(User $user, array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'order_number' => (string) random_int(10000, 99999),
            'user_id' => $user->id,
            'name' => $user->name,
            'phone' => $user->phone ?? '01627237432',
            'address' => 'Test address',
            'city' => 'Dhaka',
            'area' => 'Uttara',
            'state' => 'Dhaka',
            'subtotal' => 980,
            'delivery_charge' => 60,
            'discount' => 0,
            'total' => 1040,
            'cod_amount' => 1040,
            'due_amount' => 1040,
            'payment_status' => 'unpaid',
            'payment_method' => 'cod',
            'status' => 'new',
            'placed_at' => now(),
        ], $overrides));
    }

    #[Test]
    public function dashboard_shows_only_active_orders(): void
    {
        $user = $this->customer();
        $active = $this->orderFor($user, ['order_number' => '2001', 'status' => 'dispatched']);
        $this->orderFor($user, ['order_number' => '2002', 'status' => 'delivered']);
        $this->orderFor($user, ['order_number' => '2003', 'status' => 'cancelled']);

        $this->actingAs($user);

        Livewire::test(StorefrontAccount::class)
            ->assertSee('#2001')
            ->assertSee(__('storefront.order_status_dispatched'))
            ->assertDontSee('#2002')
            ->assertDontSee('#2003');
    }

    #[Test]
    public function order_detail_shows_courier_tracking_when_dispatched(): void
    {
        $user = $this->customer();
        $courier = Courier::query()->create([
            'name' => 'Steadfast',
            'slug' => 'steadfast',
            'charge' => 60,
            'osd_charge' => 110,
            'cod_percentage' => 1,
            'balance' => 0,
            'is_active' => true,
            'is_default' => true,
        ]);

        $order = $this->orderFor($user, [
            'order_number' => '3001',
            'status' => 'dispatched',
            'courier_id' => $courier->id,
            'courier_tracker' => 'SF123456',
            'dispatch_date' => now()->subDay(),
        ]);

        CourierData::query()->create([
            'order_id' => $order->id,
            'courier_id' => $courier->id,
            'api_data' => [
                'tracking_message' => 'Parcel is on the way',
                'delivery_status' => 'in_transit',
            ],
            'created_at' => now()->subHours(6),
        ]);

        $this->actingAs($user);

        Livewire::test(StorefrontOrderDetail::class, ['order' => $order])
            ->assertSee(__('storefront.delivery_tracking'))
            ->assertSee('Steadfast')
            ->assertSee('SF123456')
            ->assertSee('Parcel is on the way');
    }

    #[Test]
    public function order_detail_hides_courier_tracking_for_new_orders(): void
    {
        $user = $this->customer();
        $order = $this->orderFor($user, ['order_number' => '3002', 'status' => 'new']);

        $this->actingAs($user);

        Livewire::test(StorefrontOrderDetail::class, ['order' => $order])
            ->assertSee(__('storefront.order_status_new'))
            ->assertDontSee(__('storefront.delivery_tracking'));
    }
}
