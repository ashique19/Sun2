<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminExpenses;
use App\Models\Courier;
use App\Models\Expense;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\User;
use App\Services\Admin\AnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminExpensesTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    #[Test]
    public function admin_can_save_an_expense(): void
    {
        $admin = $this->adminUser();
        $this->actingAs($admin);

        Livewire::test(AdminExpenses::class)
            ->set('year', 2026)
            ->set('month', 7)
            ->set('title', 'Office rent')
            ->set('amount', '15000')
            ->set('category', 'rent')
            ->set('kind', Expense::KIND_RECURRING)
            ->set('spent_on', '2026-07-01')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Expense saved.');

        $this->assertDatabaseHas('expenses', [
            'title' => 'Office rent',
            'amount' => 15000,
            'category' => 'rent',
            'kind' => 'recurring',
            'created_by' => $admin->id,
        ]);
    }

    #[Test]
    public function can_duplicate_last_month_recurring_expenses(): void
    {
        $this->actingAs($this->adminUser());

        Expense::factory()->recurring()->create([
            'title' => 'Staff salary',
            'amount' => 40000,
            'category' => 'salary',
            'spent_on' => '2026-06-05',
        ]);

        Livewire::test(AdminExpenses::class)
            ->set('year', 2026)
            ->set('month', 7)
            ->call('duplicateLastMonthRecurring')
            ->assertSee('Copied 1 recurring expense');

        $this->assertTrue(
            Expense::query()
                ->where('title', 'Staff salary')
                ->where('kind', 'recurring')
                ->whereDate('spent_on', '2026-07-05')
                ->where('amount', 40000)
                ->exists()
        );
    }

    #[Test]
    public function analytics_indirect_uses_month_expenses(): void
    {
        $courier = Courier::query()->create([
            'name' => 'Steadfast',
            'slug' => 'steadfast',
            'cod_percentage' => 1,
            'is_active' => true,
        ]);

        $order = Order::query()->create([
            'order_number' => 'EX-'.uniqid(),
            'name' => 'Customer',
            'phone' => '01710000000',
            'address' => 'Dhaka',
            'status' => 'delivered',
            'subtotal' => 1000,
            'delivery_charge' => 80,
            'courier_charge' => 65,
            'packaging_cost' => 21,
            'collected_amount' => 1080,
            'total' => 1080,
            'courier_id' => $courier->id,
            'actual_delivery_date' => '2026-07-15 10:00:00',
            'placed_at' => now()->subDays(3),
        ]);

        OrderProduct::query()->create([
            'order_id' => $order->id,
            'name' => 'Ring',
            'quantity' => 1,
            'price' => 1000,
            'purchase_price' => 400,
            'line_total' => 1000,
        ]);

        Expense::factory()->create([
            'title' => 'Rent',
            'amount' => 100,
            'category' => 'rent',
            'kind' => Expense::KIND_RECURRING,
            'spent_on' => '2026-07-01',
        ]);

        $breakdown = app(AnalyticsService::class)->monthBreakdown(2026, 7);

        // direct = 400 + 21 + 65 + 10 = 496; profit = 1080 - 496 - 100 = 484
        $this->assertSame(100.0, $breakdown['indirect']);
        $this->assertSame(484.0, $breakdown['profit']);
    }

    #[Test]
    public function expenses_page_is_reachable(): void
    {
        $this->actingAs($this->adminUser());

        $this->get(route('admin.expenses'))
            ->assertOk()
            ->assertSee('Expenses');
    }
}
