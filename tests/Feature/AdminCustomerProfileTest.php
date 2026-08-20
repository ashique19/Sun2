<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminCustomerShow;
use App\Livewire\Admin\AdminOrderShow;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminCustomerProfileTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function customerUser(array $attributes = []): User
    {
        Role::findOrCreate('customers');

        $user = User::factory()->create(array_merge([
            'name' => 'Profile Customer',
            'phone' => '01710000001',
            'password' => 'OldPass123!',
        ], $attributes));
        $user->assignRole('customers');

        return $user;
    }

    private function orderFor(User $customer, array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'order_number' => (string) random_int(10000, 99999),
            'user_id' => $customer->id,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'address' => 'House 1',
            'city' => 'Dhaka',
            'subtotal' => 1200,
            'delivery_charge' => 80,
            'discount' => 0,
            'total' => 1280,
            'cod_amount' => 1280,
            'due_amount' => 1280,
            'payment_status' => 'unpaid',
            'payment_method' => 'cod',
            'status' => 'new',
            'placed_at' => now(),
        ], $overrides));
    }

    #[Test]
    public function order_detail_links_customer_name_to_profile(): void
    {
        $this->actingAs($this->adminUser());
        $customer = $this->customerUser();
        $order = $this->orderFor($customer);

        Livewire::test(AdminOrderShow::class, ['order' => $order])
            ->assertSeeHtml('href="'.route('admin.customers.show', $customer).'"')
            ->assertSee('Profile Customer');
    }

    #[Test]
    public function order_detail_does_not_link_when_order_has_no_user(): void
    {
        $this->actingAs($this->adminUser());
        $order = $this->orderFor($this->customerUser(), [
            'user_id' => null,
            'name' => 'Guest Buyer',
        ]);

        Livewire::test(AdminOrderShow::class, ['order' => $order])
            ->assertSee('Guest Buyer')
            ->assertDontSeeHtml(route('admin.customers.show', 1));
    }

    #[Test]
    public function customer_profile_can_reset_password_via_modal(): void
    {
        $this->actingAs($this->adminUser());
        $customer = $this->customerUser();
        $this->assertTrue(Hash::check('OldPass123!', $customer->password));

        Livewire::test(AdminCustomerShow::class, ['user' => $customer])
            ->assertSee('Reset password')
            ->assertDontSeeHtml('aria-label="Reset password"')
            ->call('openResetPasswordModal')
            ->assertSet('resetPasswordModalOpen', true)
            ->assertSeeHtml('aria-label="Reset password"')
            ->set('password', 'NewPass123!')
            ->set('password_confirmation', 'NewPass123!')
            ->call('resetPassword')
            ->assertSet('resetPasswordModalOpen', false)
            ->assertSet('message', 'Password reset for Profile Customer.')
            ->assertHasNoErrors();

        $customer->refresh();
        $this->assertTrue(Hash::check('NewPass123!', $customer->password));
        $this->assertFalse(Hash::check('OldPass123!', $customer->password));
    }

    #[Test]
    public function customer_profile_reset_password_requires_confirmation(): void
    {
        $this->actingAs($this->adminUser());
        $customer = $this->customerUser();

        Livewire::test(AdminCustomerShow::class, ['user' => $customer])
            ->call('openResetPasswordModal')
            ->set('password', 'NewPass123!')
            ->set('password_confirmation', 'Mismatch123!')
            ->call('resetPassword')
            ->assertHasErrors(['password'])
            ->assertSet('resetPasswordModalOpen', true);

        $this->assertTrue(Hash::check('OldPass123!', $customer->fresh()->password));
    }
}
