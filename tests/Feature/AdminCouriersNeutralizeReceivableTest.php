<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminCouriers;
use App\Models\Courier;
use App\Models\CourierBalanceEntry;
use App\Models\Order;
use App\Models\User;
use App\Services\Admin\CourierBalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminCouriersNeutralizeReceivableTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function steadfast(float $book = 16242): Courier
    {
        return Courier::query()->create([
            'name' => 'Steadfast',
            'slug' => 'steadfast',
            'charge' => 60,
            'osd_charge' => 110,
            'cod_percentage' => 1,
            'balance' => $book,
            'is_active' => true,
            'is_default' => true,
        ]);
    }

    private function deliveredOrder(Courier $courier, float $collected = 1080): Order
    {
        return Order::query()->create([
            'order_number' => 'NEUT-'.uniqid(),
            'name' => 'Customer',
            'phone' => '01710000099',
            'address' => 'Dhaka',
            'city' => 'Dhaka',
            'status' => 'delivered',
            'subtotal' => 1000,
            'delivery_charge' => 80,
            'courier_charge' => 60,
            'collected_amount' => $collected,
            'total' => $collected,
            'courier_id' => $courier->id,
            'actual_delivery_date' => now(),
            'placed_at' => now(),
        ]);
    }

    #[Test]
    public function neutralize_lowers_receivable_without_changing_book(): void
    {
        $courier = $this->steadfast(16242);
        $this->deliveredOrder($courier); // net receivable 1010

        $summaryBefore = app(CourierBalanceService::class)->summarize($courier->fresh());
        $this->assertSame(1010.0, $summaryBefore['receivable']);
        $this->assertSame(16242.0, $summaryBefore['book']);

        app(CourierBalanceService::class)->neutralizeReceivable($courier->fresh(), targetReceivable: 200);

        $courier->refresh();
        $this->assertSame(16242.0, (float) $courier->balance);

        $entry = CourierBalanceEntry::query()
            ->where('courier_id', $courier->id)
            ->where('type', 'prior_remittance')
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame(-810.0, (float) $entry->amount);
        $this->assertSame(16242.0, (float) $entry->balance_after);

        $summary = app(CourierBalanceService::class)->summarize($courier->fresh());
        $this->assertSame(200.0, $summary['receivable']);
        $this->assertSame(200.0, $summary['expected_api']);
        $this->assertSame(16242.0, $summary['book']);
        $this->assertSame(810.0, $summary['withdrawn']);
    }

    #[Test]
    public function neutralize_rejects_target_equal_to_current_receivable(): void
    {
        $courier = $this->steadfast();
        $this->deliveredOrder($courier);

        $this->expectException(ValidationException::class);

        app(CourierBalanceService::class)->neutralizeReceivable($courier->fresh(), targetReceivable: 1010);
    }

    #[Test]
    public function neutralize_raises_negative_receivable_without_changing_book(): void
    {
        $courier = Courier::query()->create([
            'name' => 'CarryBee',
            'slug' => 'carrybee',
            'charge' => 60,
            'osd_charge' => 110,
            'cod_percentage' => 1,
            'balance' => 0,
            'is_active' => true,
            'is_default' => false,
        ]);

        // Cancelled with collected 0 still contributes −courier_charge to receivable.
        Order::query()->create([
            'order_number' => 'NEUT-NEG-1',
            'name' => 'Returned Customer',
            'phone' => '01710000088',
            'address' => 'Dhaka',
            'city' => 'Dhaka',
            'status' => 'cancelled',
            'subtotal' => 1000,
            'delivery_charge' => 80,
            'courier_charge' => 60,
            'collected_amount' => 0,
            'total' => 1080,
            'courier_id' => $courier->id,
            'placed_at' => now(),
        ]);

        $summaryBefore = app(CourierBalanceService::class)->summarize($courier->fresh());
        $this->assertSame(-60.0, $summaryBefore['receivable']);

        app(CourierBalanceService::class)->neutralizeReceivable($courier->fresh(), targetReceivable: 0);

        $entry = CourierBalanceEntry::query()
            ->where('courier_id', $courier->id)
            ->where('type', 'prior_remittance')
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame(60.0, (float) $entry->amount);
        $this->assertSame(0.0, (float) $courier->fresh()->balance);

        $summary = app(CourierBalanceService::class)->summarize($courier->fresh());
        $this->assertSame(0.0, $summary['receivable']);
        $this->assertSame(0.0, $summary['expected_api']);
        $this->assertSame(-60.0, $summary['withdrawn']);
    }

    #[Test]
    public function couriers_page_shows_neutralize_for_negative_receivable(): void
    {
        $this->actingAs($this->adminUser());

        $courier = Courier::query()->create([
            'name' => 'CarryBee',
            'slug' => 'carrybee',
            'charge' => 60,
            'osd_charge' => 110,
            'cod_percentage' => 1,
            'balance' => 0,
            'is_active' => true,
            'is_default' => false,
        ]);

        Order::query()->create([
            'order_number' => 'NEUT-NEG-UI',
            'name' => 'Returned Customer',
            'phone' => '01710000077',
            'address' => 'Dhaka',
            'city' => 'Dhaka',
            'status' => 'returned',
            'subtotal' => 1000,
            'delivery_charge' => 80,
            'courier_charge' => 60,
            'collected_amount' => 0,
            'total' => 1080,
            'courier_id' => $courier->id,
            'placed_at' => now(),
        ]);

        Livewire::test(AdminCouriers::class)
            ->assertSee('CarryBee')
            ->assertSeeHtml('-60')
            ->assertSee('Neutralize')
            ->call('openNeutralize', $courier->id)
            ->assertSet('showNeutralizeModal', true)
            ->assertSet('neutralizeCurrentReceivable', '-60')
            ->assertSet('neutralizeTarget', '0')
            ->call('confirmNeutralize')
            ->assertSet('showNeutralizeModal', false);

        $this->assertSame(0.0, app(CourierBalanceService::class)->summarize($courier->fresh())['receivable']);
    }

    #[Test]
    public function couriers_page_can_neutralize_via_modal(): void
    {
        $this->actingAs($this->adminUser());
        $courier = $this->steadfast(5000);
        $this->deliveredOrder($courier);

        Livewire::test(AdminCouriers::class)
            ->assertSee('Neutralize')
            ->call('openNeutralize', $courier->id)
            ->assertSet('showNeutralizeModal', true)
            ->assertSet('neutralizeCurrentReceivable', '1010')
            ->set('neutralizeTarget', '100')
            ->call('confirmNeutralize')
            ->assertSet('showNeutralizeModal', false)
            ->assertSee('Prior remittances recorded');

        $this->assertSame(5000.0, (float) $courier->fresh()->balance);
        $this->assertSame(100.0, app(CourierBalanceService::class)->summarize($courier->fresh())['receivable']);
    }
}
