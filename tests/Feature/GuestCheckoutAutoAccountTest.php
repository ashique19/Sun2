<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\City;
use App\Models\Product;
use App\Models\User;
use App\Services\Storefront\CartService;
use App\Services\Storefront\CheckoutPricing;
use App\Services\Storefront\OrderPlacer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class GuestCheckoutAutoAccountTest extends TestCase
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
            'slug' => 'guest-auto-account-kurti',
            'sku' => 'TK-GUEST-AUTO-1',
            'price' => 980,
            'purchase_price' => 400,
            'stock_quantity' => 10,
            'is_published' => true,
            'display_order' => 0,
        ]);

        $city = City::query()->create([
            'name' => 'Dhaka',
            'slug' => 'dhaka-guest-auto-account',
            'division' => 'Dhaka',
            'is_dhaka' => true,
            'is_active' => true,
        ]);

        $area = Area::query()->create([
            'city_id' => $city->id,
            'name' => 'Uttara',
            'slug' => 'dhaka-uttara-guest-auto-account',
            'is_active' => true,
            'delivery_charge_upto_5' => 80,
            'delivery_charge_over_5' => 120,
        ]);

        return [$product, $area];
    }

    #[Test]
    public function guest_checkout_creates_account_and_places_order_under_it(): void
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
        $this->assertSame(auth()->id(), $order->fresh()->created_by);
        $this->assertSame('01710000000', auth()->user()->phone);
    }

    #[Test]
    public function guest_checkout_places_order_under_existing_customer_account(): void
    {
        [$product, $area] = $this->seedCheckout();

        $existing = User::factory()->create([
            'name' => 'Existing Customer',
            'phone' => '01710000001',
        ]);
        $existing->assignRole('customers');

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
            'name' => 'Existing Customer',
            'phone' => '01710000001',
            'email' => '',
            'address' => 'House 1',
            'area' => $area->name,
            'city' => $area->city->name,
            'state' => 'Dhaka',
        ]);

        $this->assertAuthenticatedAs($existing);
        $this->assertSame($existing->id, $order->fresh()->user_id);
        $this->assertSame(1, User::query()->count());
    }
}
