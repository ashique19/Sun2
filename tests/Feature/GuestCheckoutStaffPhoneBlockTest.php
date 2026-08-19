<?php

namespace Tests\Feature;

use App\Livewire\StorefrontCheckout;
use App\Models\Area;
use App\Models\City;
use App\Models\Product;
use App\Models\User;
use App\Services\Storefront\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class GuestCheckoutStaffPhoneBlockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('admin');
        Role::findOrCreate('customers');
    }

    /**
     * @return array{0: City, 1: Area}
     */
    private function seedCheckout(): array
    {
        $product = Product::query()->create([
            'name' => 'Test Kurti',
            'slug' => 'staff-block-kurti',
            'sku' => 'TK-STAFF-BLOCK-1',
            'price' => 980,
            'purchase_price' => 400,
            'stock_quantity' => 10,
            'is_published' => true,
            'display_order' => 0,
        ]);

        app(CartService::class)->add($product->id, 1);

        $city = City::query()->create([
            'name' => 'Dhaka',
            'slug' => 'dhaka-staff-block',
            'division' => 'Dhaka',
            'is_dhaka' => true,
            'is_active' => true,
        ]);

        $area = Area::query()->create([
            'city_id' => $city->id,
            'name' => 'Uttara',
            'slug' => 'dhaka-uttara-staff-block',
            'is_active' => true,
            'delivery_charge_upto_5' => 80,
            'delivery_charge_over_5' => 120,
        ]);

        return [$city, $area];
    }

    #[Test]
    public function send_otp_shows_bold_login_required_message_for_admin_phone(): void
    {
        User::factory()->create([
            'name' => 'Admin User',
            'phone' => '01710000000',
        ])->assignRole('admin');

        [$city, $area] = $this->seedCheckout();

        Livewire::test(StorefrontCheckout::class)
            ->set('name', 'Guest Shopper')
            ->set('phone', '01710000000')
            ->set('address', 'House 1')
            ->set('cityId', $city->id)
            ->set('areaId', $area->id)
            ->call('sendOtp')
            ->assertSet('step', 'details')
            ->assertSet('loginRequiredForRole', __('storefront.role_admin'))
            ->assertSeeHtml('font-bold')
            ->assertSee(__('storefront.checkout_staff_phone_blocked', ['role' => __('storefront.role_admin')]))
            ->assertSee(__('storefront.log_in_link'));
    }

    #[Test]
    public function logged_in_admin_can_still_checkout_with_their_phone(): void
    {
        $admin = User::factory()->create([
            'name' => 'Admin User',
            'phone' => '01710000000',
        ]);
        $admin->assignRole('admin');

        [$city, $area] = $this->seedCheckout();

        $this->actingAs($admin);

        Livewire::test(StorefrontCheckout::class)
            ->set('address', 'House 1')
            ->set('cityId', $city->id)
            ->set('areaId', $area->id)
            ->call('sendOtp')
            ->assertSet('loginRequiredForRole', null)
            ->assertSet('step', 'otp');
    }
}
