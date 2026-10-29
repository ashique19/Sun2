<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminCouriers;
use App\Models\Courier;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminCouriersMobileLayoutTest extends TestCase
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
            'balance' => 16242,
            'is_active' => true,
            'is_default' => true,
        ]);
    }

    #[Test]
    public function couriers_page_uses_cards_on_small_screens_and_a_table_on_large_screens(): void
    {
        $this->actingAs($this->adminUser());
        $this->steadfast();

        Livewire::test(AdminCouriers::class)
            ->assertSeeHtml('data-couriers-page')
            ->assertSeeHtml('data-couriers-cards')
            ->assertSeeHtml('lg:hidden')
            ->assertSeeHtml('data-couriers-table')
            ->assertSeeHtml('hidden overflow-hidden rounded-xl border border-[#EFE7D6] bg-white lg:block')
            ->assertSeeHtml('data-courier-card')
            ->assertSeeHtml('data-couriers-help')
            ->assertSeeHtml('data-couriers-totals')
            ->assertSeeHtml('<details')
            ->assertSee('How receivable, pending, and API relate')
            ->assertSee('Create Courier')
            ->assertSee('Refresh API')
            ->assertSeeHtml('grid grid-cols-2 gap-2 sm:flex')
            ->assertSeeHtml('min-h-11')
            ->assertSee('Steadfast')
            ->assertSee('Receivable')
            ->assertSee('Pending')
            ->assertSee('API balance')
            ->assertSee('Book')
            ->assertSee('Withdraw');
    }

    #[Test]
    public function empty_couriers_page_does_not_render_the_wide_table(): void
    {
        $this->actingAs($this->adminUser());

        Livewire::test(AdminCouriers::class)
            ->assertSee('No couriers yet.')
            ->assertDontSeeHtml('data-couriers-table')
            ->assertDontSeeHtml('data-couriers-cards');
    }

    #[Test]
    public function withdraw_and_neutralize_open_as_bottom_sheets(): void
    {
        $this->actingAs($this->adminUser());
        $courier = $this->steadfast();

        Order::query()->create([
            'order_number' => 'MOBILE-1',
            'name' => 'Customer',
            'phone' => '01710000001',
            'address' => 'Dhaka',
            'city' => 'Dhaka',
            'status' => 'delivered',
            'subtotal' => 1000,
            'delivery_charge' => 80,
            'courier_charge' => 60,
            'collected_amount' => 1080,
            'total' => 1080,
            'courier_id' => $courier->id,
            'actual_delivery_date' => now(),
            'placed_at' => now(),
        ]);

        Livewire::test(AdminCouriers::class)
            ->assertSee('Neutralize')
            ->call('openWithdraw', $courier->id)
            ->assertSet('showWithdrawModal', true)
            ->assertSeeHtml('items-end justify-center bg-black/50 p-0 sm:items-center sm:p-4')
            ->assertSeeHtml('rounded-t-2xl')
            ->call('closeWithdraw')
            ->call('openNeutralize', $courier->id)
            ->assertSet('showNeutralizeModal', true)
            ->assertSee('Neutralize receivable — Steadfast')
            ->assertSeeHtml('max-h-[90dvh]')
            ->assertSeeHtml('rounded-t-2xl');
    }
}
