<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminOrderForm;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminOrderFormSteadfastAjaxTest extends TestCase
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
            'steadfast.fraud_timeout' => 5,
            'steadfast.fraud.email' => null,
            'steadfast.fraud.password' => null,
        ]);
    }

    private function draftOrder(): Order
    {
        return Order::query()->create([
            'order_number' => 'INB-'.uniqid(),
            'name' => 'Inbox Draft Customer',
            'phone' => '01712345678',
            'address' => 'Dhaka',
            'city' => 'Dhaka',
            'status' => Order::STATUS_DRAFT,
            'subtotal' => 980,
            'delivery_charge' => 80,
            'courier_charge' => 0,
            'total' => 1060,
            'cod_amount' => 1060,
            'due_amount' => 1060,
            'collected_amount' => 0,
            'payment_status' => 'unpaid',
            'payment_method' => 'cod',
            'placed_at' => now(),
            'placed_via' => Order::PLACED_VIA_MESSENGER,
        ]);
    }

    #[Test]
    public function editing_inbox_draft_paints_without_calling_courier_api(): void
    {
        $this->configureSteadfast();
        Http::fake(fn () => throw new \RuntimeException('Courier API must not run during first paint.'));

        $this->actingAs($this->adminUser());
        $order = $this->draftOrder();

        Livewire::test(AdminOrderForm::class, ['order' => $order])
            ->assertSet('phone', '01712345678')
            ->assertSet('steadfastStatsPending', true)
            ->assertSet('steadfastStats', null)
            ->assertSeeHtml('data-admin-steadfast-stats-root')
            ->assertSeeHtml('data-admin-steadfast-stats-spinner')
            ->assertSee('Checking Steadfast');

        Http::assertNothingSent();
    }

    #[Test]
    public function lookup_phone_does_not_call_courier_api(): void
    {
        $this->configureSteadfast();
        Http::fake(fn () => throw new \RuntimeException('Courier API must not run during phone lookup.'));

        $this->actingAs($this->adminUser());

        Livewire::test(AdminOrderForm::class)
            ->set('phone', '01712345678')
            ->call('lookupPhone')
            ->assertSet('steadfastStatsPending', true)
            ->assertSet('steadfastStats', null);

        Http::assertNothingSent();
    }

    #[Test]
    public function steadfast_stats_endpoint_returns_faked_score(): void
    {
        $this->configureSteadfast();
        Http::fake([
            'portal.packzy.com/api/v1/fraud_check/score/01712345678' => Http::response([
                'status' => 200,
                'phone' => '01712345678',
                'delivery_ratio' => 88,
                'cancellation_ratio' => 12,
                'volume_band' => 'medium',
            ], 200),
        ]);

        $this->actingAs($this->adminUser());

        $this->postJson(route('admin.orders.steadfast-stats'), [
            'phone' => '01712345678',
        ])
            ->assertOk()
            ->assertJsonPath('phone', '01712345678')
            ->assertJsonPath('stats.delivery_ratio', 88)
            ->assertJsonPath('error', null);
    }

    #[Test]
    public function apply_steadfast_stats_shows_delivery_chip(): void
    {
        $this->configureSteadfast();
        Http::fake(fn () => throw new \RuntimeException('Apply must not call the courier API.'));

        $this->actingAs($this->adminUser());

        Livewire::test(AdminOrderForm::class)
            ->set('phone', '01712345678')
            ->call('lookupPhone')
            ->assertSee('Checking Steadfast')
            ->call('applySteadfastStats', [
                'data_type' => 'score',
                'delivery_ratio' => 88,
                'cancellation_ratio' => 12,
                'success_ratio' => 88,
                'volume_band_label' => 'medium (6–20)',
                'total_delivered' => 22,
                'total_cancelled' => 3,
                'total_reports' => 0,
            ], null, '01712345678')
            ->assertDontSee('Checking Steadfast')
            ->assertSee('Steadfast delivery: 88%');

        Http::assertNothingSent();
    }

    #[Test]
    public function settle_steadfast_stats_stops_spinner(): void
    {
        $this->configureSteadfast();
        Http::fake();

        $this->actingAs($this->adminUser());

        Livewire::test(AdminOrderForm::class)
            ->set('phone', '01712345678')
            ->call('lookupPhone')
            ->assertSeeHtml('data-admin-steadfast-stats-spinner')
            ->call('settleSteadfastStats', '01712345678')
            ->assertDontSeeHtml('data-admin-steadfast-stats-spinner')
            ->assertSee('Steadfast delivery history is taking too long');
    }
}
