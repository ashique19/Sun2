<?php

namespace Tests\Feature;

use App\Livewire\StorefrontCheckout;
use App\Models\Area;
use App\Models\City;
use App\Models\Product;
use App\Services\Storefront\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Livewire\Livewire;
use Tests\TestCase;

class StorefrontCheckoutOtpHintTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('app.debug', false);
        Config::set('app.env', 'local');
    }

    public function test_otp_step_hides_local_log_hint_when_debug_is_disabled(): void
    {
        $product = Product::query()->create([
            'name' => 'Test Kurti',
            'slug' => 'test-kurti',
            'sku' => 'TK-CHECKOUT-HINT-1',
            'price' => 980,
            'purchase_price' => 400,
            'stock_quantity' => 10,
            'is_published' => true,
            'display_order' => 0,
        ]);

        app(CartService::class)->add($product->id, 1);

        $city = City::query()->create([
            'name' => 'Rangamati',
            'slug' => 'chattogram-rangamati-hint',
            'division' => 'Chattogram',
            'is_dhaka' => false,
            'is_active' => true,
        ]);

        $area = Area::query()->create([
            'city_id' => $city->id,
            'name' => 'Bagaichhari',
            'slug' => 'chattogram-rangamati-bagaichhari-hint',
            'is_active' => true,
            'delivery_charge_upto_5' => 100,
            'delivery_charge_over_5' => 150,
        ]);

        Livewire::test(StorefrontCheckout::class)
            ->set('name', 'Customer')
            ->set('phone', '01627237432')
            ->set('address', 'Test address')
            ->set('cityId', $city->id)
            ->set('areaId', $area->id)
            ->call('sendOtp')
            ->assertSet('step', 'otp')
            ->assertDontSee('Local: check')
            ->assertDontSee('storage/logs/laravel.log');
    }
}
