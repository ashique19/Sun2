<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminOrderForm;
use App\Livewire\Admin\AdminProductEdit;
use App\Models\Order;
use App\Models\User;
use App\Services\Admin\CustomerLookupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminFormToastTest extends TestCase
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

            return User::factory()->create([
                'phone' => $phone,
                'name' => $name !== '' ? $name : 'Customer',
            ]);
        })->byDefault();
        $this->app->instance(CustomerLookupService::class, $lookup);
    }

    #[Test]
    public function product_validation_errors_show_toast_without_top_banner(): void
    {
        $this->actingAs($this->adminUser());

        Livewire::test(AdminProductEdit::class)
            ->set('name', '')
            ->set('slug', '')
            ->set('price', '')
            ->call('save')
            ->assertHasErrors(['name', 'slug', 'price'])
            ->assertSeeHtml('data-product-edit-error-toast')
            ->assertSeeHtml('data-admin-toast="error"')
            ->assertDontSeeHtml('rounded-lg bg-rose-50 text-rose-700 text-sm px-4 py-3 mb-4');
    }

    #[Test]
    public function order_validation_errors_show_toast_instead_of_page_top_banner(): void
    {
        $this->actingAs($this->adminUser());
        $this->mockCustomerLookup();

        Livewire::test(AdminOrderForm::class)
            ->set('phone', '')
            ->set('name', '')
            ->set('address', '')
            ->call('save')
            ->assertHasErrors()
            ->assertSeeHtml('data-order-form-toast="validation"')
            ->assertSeeHtml('data-admin-toast="error"')
            ->assertDontSeeHtml('rounded-lg bg-rose-50 text-rose-700 text-sm px-4 py-3 mb-4 break-words');
    }

    #[Test]
    public function order_success_message_renders_as_toast(): void
    {
        $this->actingAs($this->adminUser());
        $this->mockCustomerLookup();

        $order = Order::query()->create([
            'order_number' => '9002',
            'name' => 'Customer',
            'phone' => '01627237432',
            'address' => 'House 1',
            'city' => 'Dhaka',
            'subtotal' => 980,
            'delivery_charge' => 0,
            'discount' => 0,
            'total' => 980,
            'cod_amount' => 980,
            'due_amount' => 980,
            'payment_status' => 'unpaid',
            'payment_method' => 'cod',
            'status' => 'new',
            'placed_at' => now(),
        ]);

        Livewire::test(AdminOrderForm::class, ['order' => $order])
            ->set('address', 'House 2, Dhaka')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('message', 'Order updated.')
            ->assertSeeHtml('data-order-form-toast="success"')
            ->assertSee('Order updated.')
            ->assertDontSeeHtml('rounded-lg bg-emerald-50 text-emerald-700 text-sm px-4 py-3 mb-4 break-words')
            ->call('dismissMessage')
            ->assertSet('message', null);
    }
}
