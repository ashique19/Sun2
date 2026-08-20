<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminUsers;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Models\User;
use App\Services\Admin\CustomerAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminCustomersAnalyticsPillsTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function customer(string $name, string $phone): User
    {
        Role::findOrCreate('customers');
        $user = User::factory()->create([
            'name' => $name,
            'phone' => $phone,
        ]);
        $user->assignRole('customers');

        return $user;
    }

    private function orderFor(User $customer, string $city, string $status = 'new'): Order
    {
        return Order::query()->create([
            'order_number' => 'ORD-'.uniqid(),
            'user_id' => $customer->id,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'address' => 'House 1',
            'city' => $city,
            'subtotal' => 500,
            'delivery_charge' => 80,
            'discount' => 0,
            'total' => 580,
            'cod_amount' => 580,
            'due_amount' => 580,
            'payment_status' => 'unpaid',
            'payment_method' => 'cod',
            'status' => $status,
            'placed_at' => now(),
        ]);
    }

    public function test_city_analytics_pill_shows_counts_and_filters_list(): void
    {
        $admin = $this->adminUser();
        $dhaka = $this->customer('Dhaka Buyer', '01710000001');
        $ctg = $this->customer('CTG Buyer', '01710000002');
        $this->customer('No City Buyer', '01710000003');

        $this->orderFor($dhaka, 'Dhaka');
        $this->orderFor($ctg, 'Chittagong');

        $this->actingAs($admin);

        Livewire::test(AdminUsers::class, ['segment' => 'customers'])
            ->call('toggleAnalyticsPill', 'city')
            ->assertSet('analyticsPill', 'city')
            ->assertSee('Dhaka')
            ->assertSee('Chittagong')
            ->assertSee('(no city)')
            ->call('applyAnalyticsFilter', 'city', 'Dhaka')
            ->assertSet('cityFilter', 'Dhaka')
            ->assertSee('Dhaka Buyer')
            ->assertDontSee('CTG Buyer')
            ->assertDontSee('No City Buyer');
    }

    public function test_order_count_analytics_filters_customers(): void
    {
        $admin = $this->adminUser();
        $zero = $this->customer('Zero Orders', '01720000001');
        $one = $this->customer('One Order', '01720000002');
        $two = $this->customer('Two Orders', '01720000003');

        $this->orderFor($one, 'Dhaka');
        $this->orderFor($two, 'Dhaka');
        $this->orderFor($two, 'Dhaka');

        $this->actingAs($admin);

        Livewire::test(AdminUsers::class, ['segment' => 'customers'])
            ->call('toggleAnalyticsPill', 'orders')
            ->assertSee('0 orders')
            ->assertSee('1 order')
            ->assertSee('2 orders')
            ->call('applyAnalyticsFilter', 'orders', '2')
            ->assertSet('ordersMin', '2')
            ->assertSet('ordersMax', '2')
            ->assertSee('Two Orders')
            ->assertDontSee('Zero Orders')
            ->assertDontSee('One Order');
    }

    public function test_category_analytics_filters_customers(): void
    {
        $admin = $this->adminUser();
        $buyer = $this->customer('Saree Buyer', '01730000001');
        $other = $this->customer('Other Buyer', '01730000002');

        $saree = Category::query()->create(['name' => 'Saree', 'slug' => 'saree', 'is_active' => true]);
        $jewelry = Category::query()->create(['name' => 'Jewelry', 'slug' => 'jewelry', 'is_active' => true]);

        $sareeProduct = Product::query()->create([
            'name' => 'Red Saree',
            'slug' => 'red-saree',
            'price' => 1000,
            'category_id' => $saree->id,
            'is_published' => true,
        ]);
        $jewelryProduct = Product::query()->create([
            'name' => 'Ring',
            'slug' => 'ring',
            'price' => 500,
            'category_id' => $jewelry->id,
            'is_published' => true,
        ]);

        $orderA = $this->orderFor($buyer, 'Dhaka');
        OrderProduct::query()->create([
            'order_id' => $orderA->id,
            'product_id' => $sareeProduct->id,
            'name' => $sareeProduct->name,
            'quantity' => 1,
            'price' => 1000,
            'line_total' => 1000,
        ]);

        $orderB = $this->orderFor($other, 'Dhaka');
        OrderProduct::query()->create([
            'order_id' => $orderB->id,
            'product_id' => $jewelryProduct->id,
            'name' => $jewelryProduct->name,
            'quantity' => 1,
            'price' => 500,
            'line_total' => 500,
        ]);

        $this->actingAs($admin);

        Livewire::test(AdminUsers::class, ['segment' => 'customers'])
            ->call('toggleAnalyticsPill', 'category')
            ->assertSee('Saree')
            ->assertSee('Jewelry')
            ->call('applyAnalyticsFilter', 'category', (string) $saree->id)
            ->assertSet('categoryId', (string) $saree->id)
            ->assertSee('Saree Buyer')
            ->assertDontSee('Other Buyer');
    }

    public function test_analytics_service_reports_match_counts(): void
    {
        $a = $this->customer('A', '01740000001');
        $b = $this->customer('B', '01740000002');
        $this->orderFor($a, 'Dhaka');
        $this->orderFor($b, 'Dhaka');

        $service = app(CustomerAnalyticsService::class);
        $city = collect($service->byCity())->firstWhere('key', 'Dhaka');

        $this->assertNotNull($city);
        $this->assertSame(2, $city['count']);
    }
}
