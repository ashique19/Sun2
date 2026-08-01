<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminCouriers;
use App\Models\Courier;
use App\Models\CourierBalanceEntry;
use App\Models\Order;
use App\Models\User;
use App\Services\Admin\CourierBalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminCouriersBalanceBreakdownTest extends TestCase
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
            'balance' => 0,
            'is_active' => true,
            'is_default' => true,
        ]);
    }

    #[Test]
    public function summarize_splits_pending_receivable_and_withdrawals(): void
    {
        $courier = $this->steadfast();

        Order::query()->create([
            'order_number' => 'PEND-1',
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

        Order::query()->create([
            'order_number' => 'DEL-1',
            'name' => 'Delivered Customer',
            'phone' => '01710000002',
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

        CourierBalanceEntry::query()->create([
            'courier_id' => $courier->id,
            'type' => 'withdraw',
            'amount' => -200,
            'balance_after' => 0,
            'note' => 'Partial remittance',
        ]);

        $courier->update(['balance' => 1880]);

        $summary = app(CourierBalanceService::class)->summarize($courier->fresh());

        // Pending: collectable on dispatched = 1080
        $this->assertSame(1080.0, $summary['pending']);

        // Delivered remittance: 1080 - 60 courier - (1080-80)*1% COD = 1080 - 60 - 10 = 1010
        // Receivable after 200 withdraw = 810
        $this->assertSame(1010.0, round(1080 - 60 - 10, 2));
        $this->assertSame(810.0, $summary['receivable']);
        $this->assertSame(200.0, $summary['withdrawn']);

        // Expected API = book − pending = 1880 − 1080
        $this->assertSame(1880.0, $summary['book']);
        $this->assertSame(800.0, $summary['expected_api']);
    }

    #[Test]
    public function couriers_page_shows_receivable_pending_and_api_columns(): void
    {
        $this->actingAs($this->adminUser());
        $this->steadfast();

        Livewire::test(AdminCouriers::class)
            ->assertSee('Receivable')
            ->assertSee('Pending')
            ->assertSee('API balance')
            ->assertSee('Receivable = delivered COD − courier charge − COD % − withdrawals')
            ->assertSee('Pending = COD still with courier on dispatched parcels')
            ->assertSee('Expected API = book − pending')
            ->assertSee('Should be');
    }
}
