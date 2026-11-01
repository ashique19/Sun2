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

class AdminCouriersWithdrawPrefillTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function courier(array $overrides = []): Courier
    {
        return Courier::query()->create(array_merge([
            'name' => 'Steadfast',
            'slug' => 'steadfast',
            'charge' => 60,
            'osd_charge' => 110,
            'cod_percentage' => 1,
            'balance' => 5000,
            'is_active' => true,
            'is_default' => false,
        ], $overrides));
    }

    private function deliveredOrder(Courier $courier, float $collected, float $courierCharge = 60): Order
    {
        return Order::query()->create([
            'order_number' => 'WD-'.uniqid(),
            'name' => 'Customer',
            'phone' => '01710000001',
            'address' => 'Dhaka',
            'city' => 'Dhaka',
            'status' => 'delivered',
            'subtotal' => $collected - 80,
            'delivery_charge' => 80,
            'courier_charge' => $courierCharge,
            'collected_amount' => $collected,
            'total' => $collected,
            'courier_id' => $courier->id,
            'actual_delivery_date' => now(),
            'placed_at' => now(),
        ]);
    }

    #[Test]
    public function open_withdraw_prefills_rounded_receivable_for_every_courier(): void
    {
        $this->actingAs($this->adminUser());

        $steadfast = $this->courier([
            'name' => 'Steadfast',
            'slug' => 'steadfast',
            'is_default' => true,
        ]);
        $pathao = $this->courier([
            'name' => 'Pathao',
            'slug' => 'pathao',
            'charge' => 80,
        ]);

        $this->deliveredOrder($steadfast, collected: 1080, courierCharge: 60);
        $this->deliveredOrder($pathao, collected: 2080, courierCharge: 80);

        $steadfastReceivable = (int) round(
            app(CourierBalanceService::class)->summarize($steadfast->fresh())['receivable']
        );
        $pathaoReceivable = (int) round(
            app(CourierBalanceService::class)->summarize($pathao->fresh())['receivable']
        );

        $this->assertSame(1010, $steadfastReceivable);
        $this->assertGreaterThan(0, $pathaoReceivable);
        $this->assertNotSame($steadfastReceivable, $pathaoReceivable);

        Livewire::test(AdminCouriers::class)
            ->call('openWithdraw', $steadfast->id)
            ->assertSet('showWithdrawModal', true)
            ->assertSet('withdrawCourierId', $steadfast->id)
            ->assertSet('withdrawReceivable', (string) $steadfastReceivable)
            ->assertSet('withdrawAmount', (string) $steadfastReceivable)
            ->assertSeeHtml('Receivable: &#2547; '.number_format($steadfastReceivable, 0))
            ->call('closeWithdraw')
            ->assertSet('withdrawAmount', '')
            ->call('openWithdraw', $pathao->id)
            ->assertSet('withdrawCourierId', $pathao->id)
            ->assertSet('withdrawReceivable', (string) $pathaoReceivable)
            ->assertSet('withdrawAmount', (string) $pathaoReceivable)
            ->assertSeeHtml('Receivable: &#2547; '.number_format($pathaoReceivable, 0));
    }

    #[Test]
    public function open_withdraw_leaves_amount_blank_when_receivable_is_not_positive(): void
    {
        $this->actingAs($this->adminUser());

        $empty = $this->courier([
            'name' => 'RedX',
            'slug' => 'redx',
            'is_default' => true,
        ]);
        $negative = $this->courier([
            'name' => 'Carrybee',
            'slug' => 'carrybee',
        ]);

        Order::query()->create([
            'order_number' => 'WD-NEG-1',
            'name' => 'Returned Customer',
            'phone' => '01710000002',
            'address' => 'Dhaka',
            'city' => 'Dhaka',
            'status' => 'cancelled',
            'subtotal' => 1000,
            'delivery_charge' => 80,
            'courier_charge' => 60,
            'collected_amount' => 0,
            'total' => 1080,
            'courier_id' => $negative->id,
            'placed_at' => now(),
        ]);

        $this->assertSame(0.0, app(CourierBalanceService::class)->summarize($empty->fresh())['receivable']);
        $this->assertSame(-60.0, app(CourierBalanceService::class)->summarize($negative->fresh())['receivable']);

        Livewire::test(AdminCouriers::class)
            ->call('openWithdraw', $empty->id)
            ->assertSet('withdrawReceivable', '0')
            ->assertSet('withdrawAmount', '')
            ->call('closeWithdraw')
            ->call('openWithdraw', $negative->id)
            ->assertSet('withdrawReceivable', '-60')
            ->assertSet('withdrawAmount', '');
    }

    #[Test]
    public function open_withdraw_prefills_receivable_after_partial_withdrawal(): void
    {
        $this->actingAs($this->adminUser());

        $courier = $this->courier(['is_default' => true]);
        $this->deliveredOrder($courier, collected: 1080, courierCharge: 60);

        CourierBalanceEntry::query()->create([
            'courier_id' => $courier->id,
            'type' => 'withdraw',
            'amount' => -200,
            'balance_after' => 4800,
            'note' => 'Partial remittance',
        ]);

        $receivable = app(CourierBalanceService::class)->summarize($courier->fresh())['receivable'];
        $this->assertSame(809.8, $receivable);

        Livewire::test(AdminCouriers::class)
            ->call('openWithdraw', $courier->id)
            ->assertSet('withdrawReceivable', '810')
            ->assertSet('withdrawAmount', '810');
    }
}
