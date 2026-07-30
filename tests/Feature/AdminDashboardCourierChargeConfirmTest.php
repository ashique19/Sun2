<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminDashboard;
use App\Livewire\Admin\AdminOrderShow;
use App\Models\Area;
use App\Models\City;
use App\Models\Courier;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminDashboardCourierChargeConfirmTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create(['name' => 'Charge Admin']);
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
            'is_active' => true,
            'is_default' => true,
        ]);
    }

    private function area(string $cityName, string $areaName, int $upto5, bool $isDhaka = false): Area
    {
        $city = City::query()->firstOrCreate(
            ['name' => $cityName],
            [
                'is_dhaka' => $isDhaka,
                'is_active' => true,
                'slug' => strtolower(str_replace(' ', '-', $cityName)).'-'.uniqid(),
            ],
        );

        return Area::query()->create([
            'city_id' => $city->id,
            'name' => $areaName,
            'slug' => strtolower(str_replace(' ', '-', $areaName)).'-'.uniqid(),
            'is_active' => true,
            'delivery_charge_upto_5' => $upto5,
            'delivery_charge_over_5' => $upto5 + 40,
        ]);
    }

    private function dispatchedOrder(
        Courier $courier,
        string $name,
        float $charge = 60,
        ?string $consignmentId = null,
        string $city = 'Dhaka',
        ?string $area = null,
    ): Order {
        return Order::query()->create([
            'order_number' => 'DC-'.uniqid(),
            'name' => $name,
            'phone' => '01710000000',
            'address' => 'Test address',
            'city' => $city,
            'area' => $area,
            'status' => 'dispatched',
            'subtotal' => 1000,
            'delivery_charge' => 80,
            'courier_charge' => $charge,
            'total' => 1080,
            'courier_id' => $courier->id,
            'courier_tracker' => 'SF'.random_int(1000, 9999),
            'courier_consignment_id' => $consignmentId,
            'dispatch_date' => now()->subHour(),
            'placed_at' => now()->subDay(),
        ]);
    }

    #[Test]
    public function dashboard_lists_dispatched_orders_needing_courier_charge_confirmation(): void
    {
        $this->actingAs($this->adminUser());
        $courier = $this->steadfast();
        $this->dispatchedOrder($courier, 'Ayesha Akter', 60, '277193413');

        Livewire::test(AdminDashboard::class)
            ->assertSee('Confirm courier charges')
            ->assertSee('Ayesha Akter')
            ->assertSee('Steadfast ↗')
            ->assertSeeHtml('href="https://steadfast.com.bd/user/consignment/277193413"')
            ->assertSeeHtml('target="_blank"')
            ->assertSee('Charge ৳')
            ->assertSeeHtml('wire:click="confirmCourierCharge(');
    }

    #[Test]
    public function dashboard_defaults_courier_charge_from_area_delivery_charge_upto_5(): void
    {
        $this->actingAs($this->adminUser());
        $courier = $this->steadfast();

        $this->area('Dhaka', 'Mirpur', 70, true);
        $this->area('Chittagong', 'Agrabad', 130);

        $mirpur = $this->dispatchedOrder($courier, 'Mirpur Customer', 0, null, 'Dhaka', 'Mirpur');
        $agrabad = $this->dispatchedOrder($courier, 'Agrabad Customer', 0, null, 'Chittagong', 'Agrabad');

        Livewire::test(AdminDashboard::class)
            ->assertSet('pendingCourierCharges.'.$mirpur->id, '70')
            ->assertSet('pendingCourierCharges.'.$agrabad->id, '130')
            ->assertSee('Mirpur')
            ->assertSee('Agrabad');
    }

    #[Test]
    public function dashboard_falls_back_to_courier_catalog_when_area_missing(): void
    {
        $this->actingAs($this->adminUser());
        $courier = $this->steadfast();

        $dhaka = $this->dispatchedOrder($courier, 'Dhaka Customer', 0, null, 'Dhaka', null);
        $outside = $this->dispatchedOrder($courier, 'Chittagong Customer', 0, null, 'Chittagong', 'Unknown Area');

        Livewire::test(AdminDashboard::class)
            ->assertSet('pendingCourierCharges.'.$dhaka->id, '60')
            ->assertSet('pendingCourierCharges.'.$outside->id, '110');
    }

    #[Test]
    public function dashboard_can_edit_and_confirm_courier_charge(): void
    {
        $admin = $this->adminUser();
        $this->actingAs($admin);
        $courier = $this->steadfast();
        $order = $this->dispatchedOrder($courier, 'Karim Hossain', 60);

        Livewire::test(AdminDashboard::class)
            ->set('pendingCourierCharges.'.$order->id, '75')
            ->call('confirmCourierCharge', $order->id)
            ->assertHasNoErrors()
            ->assertSet('courierChargeMessage', 'Courier charge confirmed for Karim Hossain.')
            ->assertDontSee('Confirm courier charges');

        $order->refresh();
        $this->assertSame(75.0, (float) $order->courier_charge);
        $this->assertNotNull($order->courier_charge_confirmed_at);
        $this->assertSame($admin->id, (int) $order->courier_charge_confirmed_by);
    }

    #[Test]
    public function confirmed_orders_leave_the_dashboard_queue(): void
    {
        $this->actingAs($this->adminUser());
        $courier = $this->steadfast();
        $order = $this->dispatchedOrder($courier, 'Already Confirmed', 60);
        $order->update([
            'courier_charge_confirmed_at' => now(),
            'courier_charge_confirmed_by' => auth()->id(),
        ]);

        Livewire::test(AdminDashboard::class)
            ->assertDontSee('Confirm courier charges')
            ->assertDontSee('Already Confirmed');
    }

    #[Test]
    public function order_show_defaults_unconfirmed_charge_from_area_delivery_charge(): void
    {
        $this->actingAs($this->adminUser());
        $courier = $this->steadfast();
        $this->area('Sylhet', 'Zindabazar', 95);
        $order = $this->dispatchedOrder($courier, 'Show Default', 0, null, 'Sylhet', 'Zindabazar');

        Livewire::test(AdminOrderShow::class, ['order' => $order])
            ->assertSet('courierChargeOverride', '95');
    }

    #[Test]
    public function order_show_can_confirm_courier_charge(): void
    {
        $admin = $this->adminUser();
        $this->actingAs($admin);
        $courier = $this->steadfast();
        $order = $this->dispatchedOrder($courier, 'Show Confirm', 60);

        Livewire::test(AdminOrderShow::class, ['order' => $order])
            ->assertSee('Not confirmed yet')
            ->set('courierChargeOverride', '70')
            ->call('confirmCourierCharge')
            ->assertHasNoErrors()
            ->assertSet('message', 'Courier charge confirmed.')
            ->assertSee('Confirmed');

        $order->refresh();
        $this->assertSame(70.0, (float) $order->courier_charge);
        $this->assertNotNull($order->courier_charge_confirmed_at);
        $this->assertSame($admin->id, (int) $order->courier_charge_confirmed_by);
    }
}
