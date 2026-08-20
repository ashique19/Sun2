<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminCouriers;
use App\Models\Courier;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminCouriersApiBalanceLoadTest extends TestCase
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
            'balance' => 500,
            'is_active' => true,
            'is_default' => true,
        ]);
    }

    #[Test]
    public function couriers_page_renders_without_calling_api_on_initial_load(): void
    {
        config([
            'steadfast.api_key' => 'test-key',
            'steadfast.secret_key' => 'test-secret',
            'steadfast.base_url' => 'https://portal.packzy.com/api/v1',
        ]);

        Http::fake([
            'portal.packzy.com/*' => Http::response(['current_balance' => 1234], 200),
        ]);

        $this->actingAs($this->adminUser());
        $this->steadfast();

        Livewire::test(AdminCouriers::class)
            ->assertSee('Receivable')
            ->assertSee('Pending')
            ->assertSee('API balance')
            ->assertSee('Refresh API')
            ->assertDontSeeHtml('wire:init="loadApiBalances"')
            ->assertSet('apiBalancesLoaded', false)
            ->assertDontSee('৳ 1,234');

        Http::assertNothingSent();
    }

    #[Test]
    public function couriers_page_loads_api_balance_after_init(): void
    {
        config([
            'steadfast.api_key' => 'test-key',
            'steadfast.secret_key' => 'test-secret',
            'steadfast.base_url' => 'https://portal.packzy.com/api/v1',
        ]);

        Http::fake([
            'portal.packzy.com/api/v1/get_balance' => Http::response(['current_balance' => 1234], 200),
        ]);

        $this->actingAs($this->adminUser());
        $courier = $this->steadfast();

        Livewire::test(AdminCouriers::class)
            ->call('loadApiBalances')
            ->assertSet('apiBalancesLoaded', true)
            ->assertSet('apiBalanceError', null)
            ->assertSet('apiBalances.'.$courier->id, 1234.0)
            ->assertSee('1,234');
    }

    #[Test]
    public function couriers_page_shows_expected_api_and_difference_after_refresh(): void
    {
        config([
            'steadfast.api_key' => 'test-key',
            'steadfast.secret_key' => 'test-secret',
            'steadfast.base_url' => 'https://portal.packzy.com/api/v1',
        ]);

        Http::fake([
            'portal.packzy.com/api/v1/get_balance' => Http::response(['current_balance' => 700], 200),
        ]);

        $this->actingAs($this->adminUser());
        $courier = $this->steadfast();
        $courier->update(['balance' => 1500]);

        Order::query()->create([
            'order_number' => 'DEL-API-1',
            'name' => 'Delivered Customer',
            'phone' => '01710000002',
            'address' => 'Dhaka',
            'city' => 'Dhaka',
            'status' => 'delivered',
            'subtotal' => 1000,
            'delivery_charge' => 80,
            'courier_charge' => 60,
            'collected_amount' => 1080,
            'cod_amount' => 1080,
            'total' => 1080,
            'courier_id' => $courier->id,
            'actual_delivery_date' => now(),
            'placed_at' => now(),
        ]);

        Order::query()->create([
            'order_number' => 'PEND-API-1',
            'name' => 'Pending Customer',
            'phone' => '01710000001',
            'address' => 'Dhaka',
            'city' => 'Dhaka',
            'status' => 'dispatched',
            'subtotal' => 1000,
            'delivery_charge' => 80,
            'courier_charge' => 60,
            'cod_amount' => 500,
            'total' => 1080,
            'courier_id' => $courier->id,
            'dispatch_date' => now(),
            'placed_at' => now(),
        ]);

        // Expected API = book 1500; actual API 700 → diff −800
        Livewire::test(AdminCouriers::class)
            ->assertSee('Should be')
            ->assertSee('1,500')
            ->call('loadApiBalances')
            ->assertSeeHtml('&#2547; 700')
            ->assertSeeHtml('Should be &#2547; 1,500')
            ->assertSeeHtml('Diff −&#2547; 800');
    }

    #[Test]
    public function couriers_page_survives_api_connection_failure(): void
    {
        config([
            'steadfast.api_key' => 'test-key',
            'steadfast.secret_key' => 'test-secret',
            'steadfast.base_url' => 'https://portal.packzy.com/api/v1',
        ]);

        Http::fake(function () {
            throw new ConnectionException('Connection timed out');
        });

        $this->actingAs($this->adminUser());
        $courier = $this->steadfast();

        Livewire::test(AdminCouriers::class)
            ->assertSee('Steadfast')
            ->assertSee('Receivable')
            ->call('loadApiBalances')
            ->assertSet('apiBalancesLoaded', true)
            ->assertSet('apiBalances.'.$courier->id, null)
            ->assertSet('apiBalanceError', 'API balance unavailable right now.')
            ->assertSee('API balance unavailable right now.');
    }

    #[Test]
    public function couriers_page_survives_api_http_error(): void
    {
        config([
            'steadfast.api_key' => 'test-key',
            'steadfast.secret_key' => 'test-secret',
            'steadfast.base_url' => 'https://portal.packzy.com/api/v1',
        ]);

        Http::fake([
            'portal.packzy.com/api/v1/get_balance' => Http::response('Upstream error', 502),
        ]);

        $this->actingAs($this->adminUser());
        $courier = $this->steadfast();

        Livewire::test(AdminCouriers::class)
            ->call('loadApiBalances')
            ->assertSet('apiBalancesLoaded', true)
            ->assertSet('apiBalances.'.$courier->id, null)
            ->assertSet('apiBalanceError', 'API balance unavailable right now.');
    }
}
