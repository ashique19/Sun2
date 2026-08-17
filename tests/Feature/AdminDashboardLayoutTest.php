<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminDashboard;
use App\Models\AdminAttentionItem;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminDashboardLayoutTest extends TestCase
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
    public function clear_attention_state_renders_a_compact_row(): void
    {
        $this->actingAs($this->adminUser());

        Livewire::test(AdminDashboard::class)
            ->assertSeeHtml('hidden md:block')
            ->assertSeeHtml('data-admin-shortcut="products"')
            ->assertSee(route('admin.products'), false)
            ->assertDontSee(route('admin.products.create'), false)
            // Product uses a cube icon; order keeps the plain "+".
            ->assertSeeHtml('d="M10.362 1.093a.75.75 0 0 0-.724 0L2.523 5.018')
            ->assertSeeHtml('data-admin-shortcut="orders"')
            ->assertSee(route('admin.orders.new'), false)
            ->assertDontSee(route('admin.orders.create'), false)
            ->assertSeeHtml('data-admin-shortcut="inbox"')
            ->assertSee(route('admin.inbox'), false)
            ->assertSee('Admin Attention')
            ->assertSee('All clear')
            ->assertDontSee('No issues need attention at the moment.')
            ->assertDontSee('Needs Attention (')
            ->assertDontSee('Recently Resolved (')
            ->assertSeeHtml('grid-cols-3')
            ->assertSee('New')
            ->assertSee('By AI')
            ->assertSee('Dispatched')
            ->assertDontSee('View orders')
            ->assertSee('OQ')
            ->assertSee('OV')
            ->assertSee('DQ')
            ->assertSee('CV')
            ->assertSeeHtml('aria-label="Orders placed that day"')
            ->assertSeeHtml('aria-label="Value of orders placed that day"')
            ->assertSeeHtml('aria-label="Of those orders, how many later became delivered (even on a later date)"')
            ->assertSeeHtml('aria-label="Money received on those delivered orders"')
            ->assertSeeHtml('role="tooltip"')
            ->assertDontSee('Order Qty')
            ->assertDontSee('Order Value')
            ->assertDontSee('Delivery Qty')
            ->assertDontSee('Collected Value')
            ->assertSee('Orders')
            ->assertSee('This month')
            ->assertSee('Last month')
            ->assertSee('Orders by date')
            ->assertSee('Last 7 days')
            ->assertSee('Default view')
            ->assertSee(now()->format('M-d'))
            ->assertSeeHtml('table-fixed')
            ->assertDontSee('Order qty/value by placed date')
            ->assertDontSee('Last 30 Days')
            ->assertDontSee('30-day total')
            ->assertDontSee('Both months total')
            ->assertDontSee('Current month')
            ->assertDontSee('Previous month');
    }

    #[Test]
    public function small_screen_top_nav_points_products_orders_and_inbox_at_index(): void
    {
        $this->actingAs($this->adminUser());

        $html = $this->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSeeHtml('md:hidden sticky top-0')
            ->assertSeeHtml('data-admin-shortcut="products"')
            ->assertSeeHtml('data-admin-shortcut="orders"')
            ->assertSeeHtml('data-admin-shortcut="inbox"')
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/href="[^"]*\/admin\/products"[^>]*data-admin-shortcut="products"/',
            $html,
        );
        $this->assertMatchesRegularExpression(
            '/href="[^"]*\/admin\/orders\/new"[^>]*data-admin-shortcut="orders"/',
            $html,
        );
        $this->assertMatchesRegularExpression(
            '/href="[^"]*\/admin\/inbox"[^>]*data-admin-shortcut="inbox"/',
            $html,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/href="[^"]*\/admin\/products\/create"[^>]*data-admin-shortcut="products"/',
            $html,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/href="[^"]*\/admin\/orders\/create"[^>]*data-admin-shortcut="orders"/',
            $html,
        );
    }

    #[Test]
    public function new_and_dispatched_tiles_show_order_value(): void
    {
        $this->actingAs($this->adminUser());

        Order::query()->create([
            'order_number' => 'NEW-1',
            'name' => 'Customer',
            'phone' => '01627237432',
            'address' => 'Dhaka',
            'city' => 'Dhaka',
            'subtotal' => 1500,
            'delivery_charge' => 80,
            'discount' => 0,
            'total' => 1580,
            'cod_amount' => 1580,
            'due_amount' => 1580,
            'payment_status' => 'unpaid',
            'payment_method' => 'cod',
            'status' => 'new',
            'placed_at' => now(),
            'placed_via' => Order::PLACED_VIA_STOREFRONT,
        ]);
        Order::query()->create([
            'order_number' => 'DISP-1',
            'name' => 'Customer 2',
            'phone' => '01627237433',
            'address' => 'Dhaka',
            'city' => 'Dhaka',
            'subtotal' => 2000,
            'delivery_charge' => 80,
            'discount' => 0,
            'total' => 2080,
            'cod_amount' => 2080,
            'due_amount' => 2080,
            'payment_status' => 'unpaid',
            'payment_method' => 'cod',
            'status' => 'dispatched',
            'placed_at' => now(),
            'placed_via' => Order::PLACED_VIA_STOREFRONT,
        ]);

        Livewire::test(AdminDashboard::class)
            ->assertSee('New')
            ->assertSee('Dispatched')
            ->assertSee('৳1,580')
            ->assertSee('৳2,080');
    }

    #[Test]
    public function unresolved_attention_expands_into_a_compact_list(): void
    {
        $this->actingAs($this->adminUser());

        AdminAttentionItem::query()->create([
            'issue_type' => AdminAttentionItem::ISSUE_TYPE_SYSTEM_ALERT,
            'title' => 'Token refresh needed',
            'description' => 'Page token looks expired.',
        ]);

        Livewire::test(AdminDashboard::class)
            ->assertSee('Admin Attention')
            ->assertSee('1 issue needs review')
            ->assertSee('Token refresh needed')
            ->assertSee('Resolve')
            ->assertDontSee('All clear');
    }
}
