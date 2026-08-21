<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminOrderForm;
use App\Models\User;
use App\Services\Admin\CustomerLookupService;
use App\Services\Couriers\PathaoMerchantSuccessClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminOrderFormPathaoSuccessTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function mockCustomerLookup(): void
    {
        $lookup = Mockery::mock(CustomerLookupService::class);
        $lookup->shouldReceive('lookup')->andReturn([
            'phone' => '01712345678',
            'valid' => true,
            'user' => null,
            'last_order' => null,
            'order_count' => 0,
            'orders' => collect(),
            'steadfast' => [
                'success_ratio' => 90,
                'total_delivered' => 9,
                'total_parcels' => 10,
                'total_cancelled' => 1,
            ],
            'steadfast_error' => null,
        ])->byDefault();
        $lookup->shouldReceive('formDefaultsFromOrder')->andReturn([
            'name' => '',
            'email' => '',
            'address' => '',
            'cityId' => null,
            'areaId' => null,
            'location_hint' => null,
        ])->byDefault();

        $this->app->instance(CustomerLookupService::class, $lookup);
    }

    #[Test]
    public function pathao_stats_load_separately_when_scrap_enabled(): void
    {
        config([
            'pathao.scrap' => true,
            'pathao.username' => 'merchant@example.com',
            'pathao.password' => 'secret',
        ]);

        $pathao = Mockery::mock(PathaoMerchantSuccessClient::class);
        $pathao->shouldReceive('isScrapConfigured')->andReturn(true);
        $pathao->shouldReceive('successCheck')
            ->once()
            ->with('01712345678')
            ->andReturn([
                'data_type' => 'counts',
                'success_ratio' => 75,
                'total_delivered' => 3,
                'total_parcels' => 4,
                'total_cancelled' => 1,
                'customer_rating' => null,
                'risk_level' => null,
                'label' => '75% success',
            ]);
        $this->app->instance(PathaoMerchantSuccessClient::class, $pathao);

        $this->actingAs($this->adminUser());
        $this->mockCustomerLookup();

        Livewire::test(AdminOrderForm::class)
            ->set('phone', '01712345678')
            ->call('lookupPhone')
            ->assertSet('steadfastStats.success_ratio', 90)
            ->assertSet('pathaoStats', null)
            ->call('loadPathaoStats')
            ->assertSet('pathaoStats.success_ratio', 75)
            ->assertSet('pathaoStats.total_delivered', 3)
            ->assertSee('Pathao:')
            ->assertSee('delivery success 75%');
    }

    #[Test]
    public function pathao_rating_label_is_shown_when_returned(): void
    {
        config([
            'pathao.scrap' => true,
            'pathao.username' => 'merchant@example.com',
            'pathao.password' => 'secret',
        ]);

        $pathao = Mockery::mock(PathaoMerchantSuccessClient::class);
        $pathao->shouldReceive('isScrapConfigured')->andReturn(true);
        $pathao->shouldReceive('successCheck')->andReturn([
            'data_type' => 'rating',
            'success_ratio' => 95,
            'total_delivered' => null,
            'total_parcels' => null,
            'total_cancelled' => null,
            'customer_rating' => 'excellent_customer',
            'risk_level' => 'low',
            'label' => 'Excellent Customer (Low risk)',
        ]);
        $this->app->instance(PathaoMerchantSuccessClient::class, $pathao);

        $this->actingAs($this->adminUser());
        $this->mockCustomerLookup();

        Livewire::test(AdminOrderForm::class)
            ->set('phone', '01712345678')
            ->call('loadPathaoStats')
            ->assertSee('Excellent Customer (Low risk)');
    }

    #[Test]
    public function pathao_block_hidden_when_scrap_disabled(): void
    {
        config([
            'pathao.scrap' => false,
            'pathao.username' => 'merchant@example.com',
            'pathao.password' => 'secret',
        ]);

        $pathao = Mockery::mock(PathaoMerchantSuccessClient::class);
        $pathao->shouldReceive('isScrapConfigured')->andReturn(false);
        $pathao->shouldReceive('successCheck')->never();
        $this->app->instance(PathaoMerchantSuccessClient::class, $pathao);

        $this->actingAs($this->adminUser());
        $this->mockCustomerLookup();

        Livewire::test(AdminOrderForm::class)
            ->set('phone', '01712345678')
            ->call('lookupPhone')
            ->call('loadPathaoStats')
            ->assertSet('pathaoStats', null)
            ->assertDontSee('Checking Pathao');
    }
}
