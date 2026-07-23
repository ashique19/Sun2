<?php

namespace Tests\Feature;

use App\Livewire\StorefrontCheckout;
use App\Models\Area;
use App\Models\City;
use App\Models\Product;
use App\Services\Storefront\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StorefrontCheckoutAreaValidationTest extends TestCase
{
    use RefreshDatabase;

    private function seedCart(): Product
    {
        $product = Product::query()->create([
            'name' => 'Test Kurti',
            'slug' => 'test-kurti',
            'sku' => 'TK-CHECKOUT-1',
            'price' => 980,
            'purchase_price' => 400,
            'stock_quantity' => 10,
            'is_published' => true,
            'display_order' => 0,
        ]);

        app(CartService::class)->add($product->id, 1);

        return $product;
    }

    private function rangamatiWithBagaichhari(): array
    {
        $city = City::query()->create([
            'name' => 'Rangamati',
            'slug' => 'chattogram-rangamati',
            'division' => 'Chattogram',
            'is_dhaka' => false,
            'is_active' => true,
        ]);

        $area = Area::query()->create([
            'city_id' => $city->id,
            'name' => 'Bagaichhari',
            'slug' => 'chattogram-rangamati-bagaichhari',
            'is_active' => true,
            'delivery_charge_upto_5' => 100,
            'delivery_charge_over_5' => 150,
        ]);

        return [$city, $area];
    }

    public function test_send_otp_accepts_matching_city_and_area(): void
    {
        $this->seedCart();
        [$city, $area] = $this->rangamatiWithBagaichhari();

        Livewire::test(StorefrontCheckout::class)
            ->set('name', 'Customer')
            ->set('phone', '01627237432')
            ->set('address', 'সাজেক গ্রাম')
            ->set('cityId', $city->id)
            ->set('areaId', $area->id)
            ->call('sendOtp')
            ->assertHasNoErrors()
            ->assertSet('step', 'otp');
    }

    public function test_send_otp_accepts_string_ids_from_select_binding(): void
    {
        $this->seedCart();
        [$city, $area] = $this->rangamatiWithBagaichhari();

        // Browser <select> values are strings; city_id may also be string from the DB driver.
        Livewire::test(StorefrontCheckout::class)
            ->set('name', 'Customer')
            ->set('phone', '01627237432')
            ->set('address', 'সাজেক গ্রাম')
            ->set('cityId', (string) $city->id)
            ->set('areaId', (string) $area->id)
            ->call('sendOtp')
            ->assertHasNoErrors('areaId')
            ->assertSet('step', 'otp');
    }

    public function test_send_otp_rejects_area_from_another_city(): void
    {
        $this->seedCart();
        [$city] = $this->rangamatiWithBagaichhari();

        $otherCity = City::query()->create([
            'name' => 'Dhaka',
            'slug' => 'dhaka-dhaka',
            'division' => 'Dhaka',
            'is_dhaka' => true,
            'is_active' => true,
        ]);

        $otherArea = Area::query()->create([
            'city_id' => $otherCity->id,
            'name' => 'Uttara',
            'slug' => 'dhaka-uttara',
            'is_active' => true,
            'delivery_charge_upto_5' => 60,
            'delivery_charge_over_5' => 100,
        ]);

        Livewire::test(StorefrontCheckout::class)
            ->set('name', 'Customer')
            ->set('phone', '01627237432')
            ->set('address', 'সাজেক গ্রাম')
            ->set('cityId', $city->id)
            ->set('areaId', $otherArea->id)
            ->call('sendOtp')
            ->assertHasErrors(['areaId' => __('storefront.invalid_area')])
            ->assertSet('step', 'details');
    }

    public function test_selecting_area_clears_previous_area_error(): void
    {
        $this->seedCart();
        [$city, $area] = $this->rangamatiWithBagaichhari();

        Livewire::test(StorefrontCheckout::class)
            ->set('name', 'Customer')
            ->set('phone', '01627237432')
            ->set('address', 'সাজেক গ্রাম')
            ->set('cityId', $city->id)
            ->set('areaId', null)
            ->call('sendOtp')
            ->assertHasErrors('areaId')
            ->set('areaId', $area->id)
            ->assertHasNoErrors('areaId');
    }
}
