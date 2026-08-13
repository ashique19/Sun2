<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminDashboard;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Models\User;
use App\Support\AdminDashboardMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminDashboardCategoryOrdersTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function category(string $name): Category
    {
        return Category::query()->create([
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'is_active' => true,
            'display_order' => 0,
        ]);
    }

    private function product(string $name, Category $category, float $price = 500): Product
    {
        return Product::query()->create([
            'name' => $name,
            'slug' => str($name)->slug()->append('-'.uniqid())->toString(),
            'price' => $price,
            'category_id' => $category->id,
            'is_published' => true,
            'display_order' => 0,
        ]);
    }

    private function orderWithProduct(
        Product $product,
        string $status,
        Carbon $placedAt,
        float $lineTotal,
        int $qty = 1,
    ): Order {
        $order = Order::query()->create([
            'order_number' => 'CAT-'.uniqid(),
            'name' => 'Customer',
            'phone' => '0171'.random_int(1000000, 9999999),
            'address' => 'Dhaka',
            'city' => 'Dhaka',
            'status' => $status,
            'subtotal' => $lineTotal,
            'delivery_charge' => 80,
            'total' => $lineTotal + 80,
            'collected_amount' => $status === 'delivered' ? $lineTotal + 80 : 0,
            'paid_amount' => $status === 'delivered' ? $lineTotal + 80 : 0,
            'placed_at' => $placedAt,
            'actual_delivery_date' => $status === 'delivered' ? $placedAt->copy()->addDay() : null,
            'placed_via' => Order::PLACED_VIA_STOREFRONT,
        ]);

        OrderProduct::query()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'name' => $product->name,
            'quantity' => $qty,
            'price' => $product->price,
            'purchase_price' => 100,
            'line_total' => $lineTotal,
        ]);

        return $order;
    }

    #[Test]
    public function dashboard_shows_order_and_delivery_by_category_section(): void
    {
        $this->actingAs($this->adminUser());

        Livewire::test(AdminDashboard::class)
            ->assertSee('Order and delivery by category')
            ->assertSee('This month')
            ->assertSee('Last month');
    }

    #[Test]
    public function category_breakdown_uses_placement_month_cohort(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-13 12:00:00', 'Asia/Dhaka'));

        $earrings = $this->category('Earrings');
        $necklaces = $this->category('Necklaces');
        $earringProduct = $this->product('Jhumka', $earrings, 500);
        $necklaceProduct = $this->product('Chain', $necklaces, 800);

        $thisMonth = Carbon::parse('2026-08-05 10:00:00', 'Asia/Dhaka')->utc();
        $lastMonth = Carbon::parse('2026-07-20 10:00:00', 'Asia/Dhaka')->utc();

        // This month: 2 earring orders (1 delivered), 1 necklace (open)
        $this->orderWithProduct($earringProduct, 'delivered', $thisMonth, 500);
        $this->orderWithProduct($earringProduct, 'new', $thisMonth->copy()->addHour(), 500);
        $this->orderWithProduct($necklaceProduct, 'new', $thisMonth->copy()->addHours(2), 800);

        // Last month: 1 necklace delivered
        $this->orderWithProduct($necklaceProduct, 'delivered', $lastMonth, 800);

        $report = AdminDashboardMetrics::orderAndDeliveryByCategory(fresh: true);
        $byName = collect($report['rows'])->keyBy('name');

        $this->assertSame('This month', $report['this_month']['label']);
        $this->assertSame('Last month', $report['last_month']['label']);

        $this->assertSame(2, $byName['Earrings']['this_month']['order_qty']);
        $this->assertSame(1, $byName['Earrings']['this_month']['delivery_qty']);
        $this->assertSame(1000.0, $byName['Earrings']['this_month']['order_value']);
        $this->assertSame(500.0, $byName['Earrings']['this_month']['delivery_value']);
        $this->assertSame(0, $byName['Earrings']['last_month']['order_qty']);

        $this->assertSame(1, $byName['Necklaces']['this_month']['order_qty']);
        $this->assertSame(0, $byName['Necklaces']['this_month']['delivery_qty']);
        $this->assertSame(800.0, $byName['Necklaces']['this_month']['order_value']);
        $this->assertSame(1, $byName['Necklaces']['last_month']['order_qty']);
        $this->assertSame(1, $byName['Necklaces']['last_month']['delivery_qty']);
        $this->assertSame(800.0, $byName['Necklaces']['last_month']['delivery_value']);

        // Sorted by this-month order qty (Earrings 2 before Necklaces 1).
        $this->assertSame('Earrings', $report['rows'][0]['name']);

        Livewire::test(AdminDashboard::class)
            ->assertSee('Earrings')
            ->assertSee('Necklaces');

        Carbon::setTestNow();
    }
}
