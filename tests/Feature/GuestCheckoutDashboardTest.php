<?php

namespace Tests\Feature;

use App\Livewire\StorefrontAccount;
use App\Livewire\StorefrontOrderConfirmation;
use App\Models\Area;
use App\Models\City;
use App\Models\Product;
use App\Services\Storefront\CartService;
use App\Services\Storefront\CheckoutPricing;
use App\Services\Storefront\OrderPlacer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class GuestCheckoutDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('customers');
    }

    /**
     * @return array{0: Product, 1: Area}
     */
    private function seedCheckout(): array
    {
        $product = Product::query()->create([
            'name' => 'Test Kurti',
            'slug' => 'guest-dashboard-kurti',
            'sku' => 'TK-GUEST-DASH-1',
            'price' => 980,
            'purchase_price' => 400,
            'stock_quantity' => 10,
            'is_published' => true,
            'display_order' => 0,
        ]);

        $city = City::query()->create([
            'name' => 'Dhaka',
            'slug' => 'dhaka-guest-dashboard',
            'division' => 'Dhaka',
            'is_dhaka' => true,
            'is_active' => true,
        ]);

        $area = Area::query()->create([
            'city_id' => $city->id,
            'name' => 'Uttara',
            'slug' => 'dhaka-uttara-guest-dashboard',
            'is_active' => true,
            'delivery_charge_upto_5' => 80,
            'delivery_charge_over_5' => 120,
        ]);

        return [$product, $area];
    }

    #[Test]
    public function guest_checkout_logs_in_customer_and_links_order(): void
    {
        [$product, $area] = $this->seedCheckout();

        $cart = app(CartService::class);
        $cart->clear();
        $cart->add($product->id, 1);

        $pricing = CheckoutPricing::calculate(
            $cart->subtotal(),
            $area,
            $cart->count(),
            [],
            $cart->lines(),
        );

        $this->assertGuest();

        $order = app(OrderPlacer::class)->place($cart, $pricing, [
            'name' => 'Guest Customer',
            'phone' => '01710000000',
            'email' => '',
            'address' => 'House 1',
            'area' => $area->name,
            'city' => $area->city->name,
            'state' => 'Dhaka',
        ]);

        $this->assertAuthenticated();
        $this->assertSame(auth()->id(), $order->fresh()->user_id);
        $this->assertNull(auth()->user()->password);
    }

    #[Test]
    public function confirmation_offers_dashboard_tracking_for_guest_checkout(): void
    {
        [$product, $area] = $this->seedCheckout();

        $cart = app(CartService::class);
        $cart->clear();
        $cart->add($product->id, 1);

        $pricing = CheckoutPricing::calculate(
            $cart->subtotal(),
            $area,
            $cart->count(),
            [],
            $cart->lines(),
        );

        $order = app(OrderPlacer::class)->place($cart, $pricing, [
            'name' => 'Guest Customer',
            'phone' => '01710000001',
            'email' => '',
            'address' => 'House 1',
            'area' => $area->name,
            'city' => $area->city->name,
            'state' => 'Dhaka',
        ]);

        Livewire::test(StorefrontOrderConfirmation::class, ['order' => $order->fresh()])
            ->assertSee(__('storefront.track_order_btn'))
            ->assertSee(__('storefront.go_to_dashboard_btn'))
            ->assertSee(__('storefront.track_order_hint'))
            ->assertSee('flex flex-col sm:flex-row flex-wrap items-center justify-center gap-3', false)
            ->assertDontSee('storage/logs/laravel.log');
    }

    #[Test]
    public function dashboard_prompts_passwordless_customer_to_set_password(): void
    {
        [$product, $area] = $this->seedCheckout();

        $cart = app(CartService::class);
        $cart->clear();
        $cart->add($product->id, 1);

        $pricing = CheckoutPricing::calculate(
            $cart->subtotal(),
            $area,
            $cart->count(),
            [],
            $cart->lines(),
        );

        app(OrderPlacer::class)->place($cart, $pricing, [
            'name' => 'Guest Customer',
            'phone' => '01710000002',
            'email' => '',
            'address' => 'House 1',
            'area' => $area->name,
            'city' => $area->city->name,
            'state' => 'Dhaka',
        ]);

        Livewire::test(StorefrontAccount::class)
            ->assertSee('storefront-account-nav__pills', false)
            ->assertSee(__('storefront.set_password_title'))
            ->set('password', 'SecretPass1!')
            ->set('password_confirmation', 'SecretPass1!')
            ->call('setPassword')
            ->assertSee(__('storefront.password_set_success'));

        $this->assertNotNull(auth()->user()->fresh()->password);
    }
}
