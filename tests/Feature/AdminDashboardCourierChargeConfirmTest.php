<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminDashboard;
use App\Livewire\Admin\AdminOrderShow;
use App\Models\Area;
use App\Models\City;
use App\Models\Courier;
use App\Models\Order;
use App\Models\OrderProduct;
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

    private function area(
        string $cityName,
        string $areaName,
        int $upto5,
        bool $isDhaka = false,
        ?string $unitType = null,
    ): Area {
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
            'unit_type' => $unitType,
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
        int $productQuantity = 1,
        float $packagingCost = 0,
    ): Order {
        $order = Order::query()->create([
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
            'packaging_cost' => $packagingCost,
            'total' => 1080,
            'courier_id' => $courier->id,
            'courier_tracker' => 'SF'.random_int(1000, 9999),
            'courier_consignment_id' => $consignmentId,
            'dispatch_date' => now()->subHour(),
            'placed_at' => now()->subDay(),
        ]);

        if ($productQuantity > 0) {
            OrderProduct::query()->create([
                'order_id' => $order->id,
                'name' => 'Product',
                'quantity' => $productQuantity,
                'price' => 1000,
                'purchase_price' => 400,
                'line_total' => 1000 * $productQuantity,
            ]);
        }

        return $order->fresh(['items']);
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
            ->assertSee('Courier ৳')
            ->assertSee('Pack ৳')
            ->assertSeeHtml('wire:click="confirmCourierCharge(');
    }

    #[Test]
    public function dashboard_defaults_courier_charge_from_existing_or_catalog_not_customer_delivery(): void
    {
        $this->actingAs($this->adminUser());
        $courier = $this->steadfast();

        // Customer delivery catalog rates — must NOT become courier confirm defaults.
        $this->area('Dhaka', 'Mirpur', 70, true, 'thana');
        $this->area('Chittagong', 'Agrabad', 130, false, 'thana');

        $withEstimate = $this->dispatchedOrder($courier, 'Has Estimate', 72, null, 'Dhaka', 'Mirpur');
        $dhakaZero = $this->dispatchedOrder($courier, 'Mirpur Customer', 0, null, 'Dhaka', 'Mirpur');
        $outsideZero = $this->dispatchedOrder($courier, 'Agrabad Customer', 0, null, 'Chittagong', 'Agrabad');

        Livewire::test(AdminDashboard::class)
            ->assertSet('pendingCourierCharges.'.$withEstimate->id, '72')
            ->assertSet('pendingCourierCharges.'.$dhakaZero->id, '60')
            ->assertSet('pendingCourierCharges.'.$outsideZero->id, '110')
            ->assertSee('What the courier charges us')
            ->assertSee('Mirpur')
            ->assertSee('Agrabad');
    }

    #[Test]
    public function dashboard_shows_area_based_quick_charge_badges_and_applies_them(): void
    {
        $this->actingAs($this->adminUser());
        $courier = $this->steadfast();

        $this->area('Dhaka', 'Mirpur', 70, true, 'thana');
        $this->area('Dhaka', 'Savar', 120, true, 'upazila');
        $this->area('Chittagong', 'Agrabad', 130, false, 'thana');

        $thana = $this->dispatchedOrder($courier, 'Thana Customer', 0, null, 'Dhaka', 'Mirpur');
        $upazila = $this->dispatchedOrder($courier, 'Upazila Customer', 0, null, 'Dhaka', 'Savar');
        $outside = $this->dispatchedOrder($courier, 'Outside Customer', 0, null, 'Chittagong', 'Agrabad');

        Livewire::test(AdminDashboard::class)
            ->assertSeeHtml('wire:click="applyCourierChargePreset('.$thana->id.', 65)"')
            ->assertSeeHtml('wire:click="applyCourierChargePreset('.$thana->id.', 75)"')
            ->assertSeeHtml('wire:click="applyCourierChargePreset('.$upazila->id.', 125)"')
            ->assertSeeHtml('wire:click="applyCourierChargePreset('.$outside->id.', 135)"')
            ->assertSeeHtml('wire:click="applyCourierChargePreset('.$outside->id.', 155)"')
            ->call('applyCourierChargePreset', $thana->id, 75)
            ->assertSet('pendingCourierCharges.'.$thana->id, '75')
            ->call('applyCourierChargePreset', $upazila->id, 125)
            ->assertSet('pendingCourierCharges.'.$upazila->id, '125')
            ->call('applyCourierChargePreset', $outside->id, 155)
            ->assertSet('pendingCourierCharges.'.$outside->id, '155');
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
    public function dashboard_defaults_packaging_cost_by_product_quantity(): void
    {
        $this->actingAs($this->adminUser());
        $courier = $this->steadfast();

        $one = $this->dispatchedOrder($courier, 'One Item', 60, null, 'Dhaka', null, 1);
        $two = $this->dispatchedOrder($courier, 'Two Items', 60, null, 'Dhaka', null, 2);
        $three = $this->dispatchedOrder($courier, 'Three Items', 60, null, 'Dhaka', null, 3);

        Livewire::test(AdminDashboard::class)
            ->assertSet('pendingPackagingCosts.'.$one->id, '21')
            ->assertSet('pendingPackagingCosts.'.$two->id, '30')
            ->assertSet('pendingPackagingCosts.'.$three->id, '41');
    }

    #[Test]
    public function dashboard_can_edit_and_confirm_courier_charge(): void
    {
        $admin = $this->adminUser();
        $this->actingAs($admin);
        $courier = $this->steadfast();
        $order = $this->dispatchedOrder($courier, 'Karim Hossain', 60, null, 'Dhaka', null, 2);

        Livewire::test(AdminDashboard::class)
            ->assertSet('pendingPackagingCosts.'.$order->id, '30')
            ->set('pendingCourierCharges.'.$order->id, '75')
            ->set('pendingPackagingCosts.'.$order->id, '35')
            ->call('confirmCourierCharge', $order->id)
            ->assertHasNoErrors()
            ->assertSet('courierChargeMessage', 'Courier charge and packaging updated for Karim Hossain.')
            ->assertDontSee('Confirm courier charges');

        $order->refresh();
        $this->assertSame(75.0, (float) $order->courier_charge);
        $this->assertSame(35.0, (float) $order->packaging_cost);
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
    public function order_show_defaults_unconfirmed_charge_from_courier_catalog_not_customer_delivery(): void
    {
        $this->actingAs($this->adminUser());
        $courier = $this->steadfast();
        $this->area('Sylhet', 'Zindabazar', 95);
        $order = $this->dispatchedOrder($courier, 'Show Default', 0, null, 'Sylhet', 'Zindabazar');

        Livewire::test(AdminOrderShow::class, ['order' => $order])
            ->assertSet('courierChargeOverride', '110');
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
