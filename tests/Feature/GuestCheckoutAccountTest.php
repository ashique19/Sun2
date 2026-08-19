<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Services\Storefront\GuestCheckoutAccountService;
use App\Services\Storefront\GuestCheckoutStaffPhoneException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class GuestCheckoutAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('customers');
    }

    #[Test]
    public function it_creates_passwordless_customer_and_links_guest_orders(): void
    {
        $orphan = Order::query()->create([
            'order_number' => '9001',
            'user_id' => null,
            'name' => 'Guest Shopper',
            'phone' => '01710000000',
            'address' => 'Dhaka',
            'city' => 'Dhaka',
            'status' => 'delivered',
            'subtotal' => 500,
            'delivery_charge' => 80,
            'total' => 580,
            'cod_amount' => 580,
            'due_amount' => 0,
            'payment_status' => 'paid',
            'payment_method' => 'cod',
            'placed_at' => now()->subDay(),
        ]);

        $user = app(GuestCheckoutAccountService::class)->resolveCheckoutAccount([
            'name' => 'Guest Shopper',
            'phone' => '01710000000',
            'email' => null,
        ]);

        $this->assertNotNull($user);
        $this->assertNull($user->password);
        $this->assertTrue($user->hasRole('customers'));
        $this->assertSame($user->id, $orphan->fresh()->user_id);
    }

    #[Test]
    public function it_reuses_existing_customer_account_by_phone(): void
    {
        $existing = User::factory()->create([
            'name' => 'Existing Customer',
            'phone' => '01710000000',
        ]);
        $existing->assignRole('customers');

        $user = app(GuestCheckoutAccountService::class)->resolveCheckoutAccount([
            'name' => 'Checkout Name',
            'phone' => '01710000000',
            'email' => null,
        ]);

        $this->assertNotNull($user);
        $this->assertSame($existing->id, $user->id);
        $this->assertSame('Existing Customer', $user->fresh()->name);
        $this->assertSame(1, User::query()->count());
    }

    #[Test]
    public function it_blocks_guest_checkout_for_staff_accounts_with_same_phone(): void
    {
        Role::findOrCreate('admin');

        User::factory()->create([
            'name' => 'Staff',
            'phone' => '01710000000',
        ])->assignRole('admin');

        $this->expectException(GuestCheckoutStaffPhoneException::class);
        $this->expectExceptionMessage(__('storefront.checkout_staff_phone_blocked', ['role' => __('storefront.role_admin')]));

        app(GuestCheckoutAccountService::class)->resolveCheckoutAccount([
            'name' => 'Guest Shopper',
            'phone' => '01710000000',
            'email' => null,
        ]);
    }

    #[Test]
    public function it_reports_reseller_role_for_blocked_guest_checkout_phone(): void
    {
        Role::findOrCreate('reseller');

        User::factory()->create([
            'name' => 'Reseller',
            'phone' => '01710000000',
        ])->assignRole('reseller');

        $role = app(GuestCheckoutAccountService::class)->blockedRoleForGuestCheckout('01710000000');

        $this->assertSame(__('storefront.role_reseller'), $role);
    }
}
