<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminDashboard;
use App\Models\Courier;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminDashboardSteadfastBalanceTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function steadfast(float $balance = 0): Courier
    {
        return Courier::query()->create([
            'name' => 'Steadfast',
            'slug' => 'steadfast',
            'charge' => 60,
            'osd_charge' => 110,
            'cod_percentage' => 1,
            'balance' => $balance,
            'is_active' => true,
            'is_default' => true,
        ]);
    }

    #[Test]
    public function dashboard_shows_steadfast_api_balance_should_be_value(): void
    {
        $this->actingAs($this->adminUser());

        $courier = $this->steadfast(1880);

        Order::query()->create([
            'order_number' => 'PEND-DASH-1',
            'name' => 'Pending Customer',
            'phone' => '01710000001',
            'address' => 'Dhaka',
            'city' => 'Dhaka',
            'status' => 'dispatched',
            'subtotal' => 1000,
            'delivery_charge' => 80,
            'courier_charge' => 60,
            'cod_amount' => 1080,
            'total' => 1080,
            'courier_id' => $courier->id,
            'dispatch_date' => now(),
            'placed_at' => now(),
        ]);

        // expected_api = book − pending = 1880 − 1080 = 800
        Livewire::test(AdminDashboard::class)
            ->assertSeeHtml('data-steadfast-expected-api')
            ->assertSee('Steadfast')
            ->assertSee('API balance should be')
            ->assertSeeHtml('&#2547; 800')
            ->assertSeeHtml(route('admin.couriers'));
    }

    #[Test]
    public function dashboard_hides_steadfast_should_be_when_courier_missing(): void
    {
        $this->actingAs($this->adminUser());

        Livewire::test(AdminDashboard::class)
            ->assertDontSeeHtml('data-steadfast-expected-api')
            ->assertDontSee('API balance should be');
    }
}
