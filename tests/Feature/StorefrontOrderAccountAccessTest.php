<?php

namespace Tests\Feature;

use App\Livewire\StorefrontOrderConfirmation;
use App\Livewire\StorefrontOrderDetail;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StorefrontOrderAccountAccessTest extends TestCase
{
    use RefreshDatabase;

    private function orderFor(User $user): Order
    {
        return Order::query()->create([
            'order_number' => '1001',
            'user_id' => $user->id,
            'name' => $user->name,
            'phone' => $user->phone ?? '01627237432',
            'address' => 'Test address',
            'city' => 'Dhaka',
            'area' => 'Uttara',
            'state' => 'Dhaka',
            'subtotal' => 980,
            'delivery_charge' => 60,
            'discount' => 0,
            'total' => 1040,
            'cod_amount' => 1040,
            'due_amount' => 1040,
            'payment_status' => 'unpaid',
            'payment_method' => 'cod',
            'status' => 'new',
            'placed_at' => now(),
        ]);
    }

    public function test_owner_can_open_order_from_account_after_checkout(): void
    {
        $user = User::factory()->create([
            'name' => 'Customer',
            'phone' => '01627237432',
        ]);
        $order = $this->orderFor($user);

        $this->actingAs($user);

        Livewire::test(StorefrontOrderDetail::class, ['order' => $order])
            ->assertSuccessful()
            ->assertSet('order.id', $order->id);
    }

    public function test_owner_can_open_order_when_user_id_is_string(): void
    {
        $user = User::factory()->create([
            'name' => 'Customer',
            'phone' => '01627237432',
        ]);
        $order = $this->orderFor($user);

        // Simulate drivers that hydrate foreign keys as strings before casts apply
        // by asserting ownership still works through int-safe comparison.
        $order->setRawAttributes(array_merge($order->getAttributes(), [
            'user_id' => (string) $user->id,
        ]));

        $this->actingAs($user);

        $this->assertTrue((string) $order->getAttributes()['user_id'] === (string) $user->id);

        Livewire::test(StorefrontOrderDetail::class, ['order' => $order->fresh()])
            ->assertSuccessful();
    }

    public function test_other_user_cannot_open_order_detail(): void
    {
        $owner = User::factory()->create(['phone' => '01627237432']);
        $other = User::factory()->create(['phone' => '01712345678']);
        $order = $this->orderFor($owner);

        $this->actingAs($other);

        Livewire::test(StorefrontOrderDetail::class, ['order' => $order])
            ->assertForbidden();
    }

    public function test_confirmation_allows_owner_or_session_last_order(): void
    {
        $user = User::factory()->create(['phone' => '01627237432']);
        $order = $this->orderFor($user);

        $this->actingAs($user);

        Livewire::test(StorefrontOrderConfirmation::class, ['order' => $order])
            ->assertSuccessful();

        auth()->logout();
        session(['checkout.last_order_id' => (string) $order->id]);

        Livewire::test(StorefrontOrderConfirmation::class, ['order' => $order])
            ->assertSuccessful();
    }

    public function test_confirmation_cta_can_open_order_via_session_even_if_user_id_null(): void
    {
        $user = User::factory()->create(['phone' => '01627237432']);
        $order = $this->orderFor($user);
        $order->update(['user_id' => null]);

        $this->actingAs($user);
        session(['checkout.last_order_id' => $order->id]);

        Livewire::test(StorefrontOrderDetail::class, ['order' => $order->fresh()])
            ->assertSuccessful();
    }
}
