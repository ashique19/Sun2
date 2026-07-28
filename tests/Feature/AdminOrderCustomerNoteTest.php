<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminOrderForm;
use App\Livewire\Admin\AdminOrderShow;
use App\Models\Order;
use App\Models\User;
use App\Services\Admin\CustomerLookupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminOrderCustomerNoteTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function order(array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'order_number' => (string) random_int(10000, 99999),
            'name' => 'Customer',
            'phone' => '01627237432',
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
    public function order_show_can_edit_and_clear_customer_note(): void
    {
        $this->actingAs($this->adminUser());

        $order = $this->order([
            'customer_note' => 'Old courier instruction',
        ]);

        Livewire::test(AdminOrderShow::class, ['order' => $order])
            ->assertSet('customerNote', 'Old courier instruction')
            ->assertSee('Customer note')
            ->assertSee('Save note')
            ->set('customerNote', 'Call before delivery')
            ->call('saveCustomerNote')
            ->assertSee('Customer note saved')
            ->assertSet('customerNote', 'Call before delivery');

        $this->assertSame('Call before delivery', $order->fresh()->customer_note);

        Livewire::test(AdminOrderShow::class, ['order' => $order->fresh()])
            ->call('clearCustomerNote')
            ->assertSet('customerNote', '')
            ->assertSee('Customer note saved');

        $this->assertNull($order->fresh()->customer_note);
    }

    #[Test]
    public function order_form_can_update_customer_note(): void
    {
        $this->actingAs($this->adminUser());

        $lookup = Mockery::mock(CustomerLookupService::class);
        $lookup->shouldReceive('lookup')->andReturn([
            'phone' => '01627237432',
            'valid' => true,
            'user' => null,
            'last_order' => null,
            'order_count' => 0,
            'orders' => collect(),
            'steadfast' => null,
            'steadfast_error' => null,
        ])->byDefault();
        $lookup->shouldReceive('findOrCreateCustomer')->andReturnUsing(function (string $phone, string $name) {
            Role::findOrCreate('customers');

            $user = User::query()->where('phone', $phone)->first();
            if ($user) {
                return $user;
            }

            $user = User::factory()->create([
                'name' => $name,
                'phone' => $phone,
            ]);
            $user->assignRole('customers');

            return $user;
        })->byDefault();
        $lookup->shouldReceive('formDefaultsFromOrder')->andReturn([
            'name' => '',
            'email' => '',
            'address' => '',
            'cityId' => null,
            'areaId' => null,
            'location_hint' => null,
        ])->byDefault();
        $this->app->instance(CustomerLookupService::class, $lookup);

        $order = $this->order([
            'customer_note' => 'Dump that should be editable',
        ]);

        Livewire::test(AdminOrderForm::class, ['order' => $order])
            ->assertSet('customerNote', 'Dump that should be editable')
            ->assertSee('Customer note')
            ->set('customerNote', 'Leave at gate')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('error', null);

        $this->assertSame('Leave at gate', $order->fresh()->customer_note);

        Livewire::test(AdminOrderForm::class, ['order' => $order->fresh()])
            ->set('customerNote', '')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('error', null);

        $this->assertNull($order->fresh()->customer_note);
    }
}
