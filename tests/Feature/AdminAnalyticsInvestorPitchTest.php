<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminAnalytics;
use App\Livewire\Admin\AdminAnalyticsInvestorPitch;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Models\User;
use App\Services\Admin\InvestorPitchAnalyticsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminAnalyticsInvestorPitchTest extends TestCase
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
    public function hub_lists_investor_pitch_tile(): void
    {
        $this->actingAs($this->adminUser());

        Livewire::test(AdminAnalytics::class)
            ->assertSee('Investor pitch')
            ->assertSee('Yearly traction')
            ->assertDontSee('Auto-refresh');
    }

    #[Test]
    public function investor_pitch_page_renders_yearly_metrics_with_prior_year(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-11 12:00:00', 'Asia/Dhaka'));

        $category = Category::query()->create([
            'name' => 'Necklaces',
            'slug' => 'necklaces',
            'is_homepage' => true,
            'display_order' => 1,
        ]);
        $product = Product::query()->create([
            'name' => 'Jhumka Set',
            'slug' => 'jhumka-set',
            'price' => 1500,
            'purchase_price' => 600,
            'category_id' => $category->id,
            'is_published' => true,
        ]);

        $this->seedOrder([
            'status' => 'delivered',
            'total' => 1580,
            'collected_amount' => 1580,
            'delivery_charge' => 80,
            'courier_charge' => 60,
            'placed_at' => '2026-07-15 10:00:00',
            'placed_via' => 'admin',
            'city' => 'Dhaka',
            'product' => $product,
            'price' => 1500,
            'purchase_price' => 600,
        ]);
        $this->seedOrder([
            'status' => 'dispatched',
            'total' => 1000,
            'collected_amount' => 0,
            'delivery_charge' => 80,
            'courier_charge' => 60,
            'placed_at' => '2026-07-20 10:00:00',
            'placed_via' => 'messenger',
            'city' => 'Chittagong',
            'product' => $product,
            'price' => 920,
            'purchase_price' => 400,
        ]);
        $this->seedOrder([
            'status' => 'delivered',
            'total' => 1200,
            'collected_amount' => 1200,
            'delivery_charge' => 80,
            'courier_charge' => 60,
            'placed_at' => '2025-06-01 10:00:00',
            'placed_via' => 'admin',
            'city' => 'Dhaka',
            'product' => $product,
            'price' => 1120,
            'purchase_price' => 500,
        ]);

        $this->actingAs($this->adminUser());

        Livewire::test(AdminAnalyticsInvestorPitch::class)
            ->assertSet('year', 2026)
            ->assertSee('Investor pitch deck')
            ->assertSee('Sundoritoma')
            ->assertSeeHtml('wire:click="selectYear(2026)"')
            ->assertSeeHtml('wire:click="selectYear(2025)"')
            ->assertSee('2026 YTD')
            ->assertSee('2025 same period')
            ->assertSee('Placed GMV')
            ->assertSee('Unit economics')
            ->assertSee('admin')
            ->assertSee('messenger')
            ->assertSee('Dhaka')
            ->assertSee('Necklaces')
            ->assertSee('Methodology notes')
            ->assertDontSee('auto-refreshes')
            ->assertDontSee('Refresh now')
            ->assertSee('Share with an investor')
            ->assertSee('Create share link')
            ->call('selectYear', 2025)
            ->assertSet('year', 2025)
            ->assertSee('2025')
            ->assertSee('2024');

        $this->get(route('admin.analytics.investor-pitch'))->assertOk();

        $deck = app(InvestorPitchAnalyticsService::class)->deck(2026);
        $this->assertSame(2026, $deck['year']);
        $this->assertSame(2025, $deck['prior_year']);
        $this->assertTrue($deck['is_partial_year']);
        $this->assertSame(2, $deck['traction']['orders']);
        $this->assertSame(2580.0, $deck['traction']['gmv_placed']);
        $this->assertSame(1, $deck['traction']['delivered']);
        $this->assertSame(1580.0, $deck['traction']['collected']);
        $this->assertSame(1, $deck['prior']['orders']);
        $this->assertSame(1200.0, $deck['prior']['gmv_placed']);
        $this->assertNotNull($deck['unit_economics']['gm_pct_known']);

        $deck2025 = app(InvestorPitchAnalyticsService::class)->deck(2025);
        $this->assertFalse($deck2025['is_partial_year']);
        $this->assertSame(1, $deck2025['traction']['orders']);
        $this->assertSame(1200.0, $deck2025['traction']['gmv_placed']);

        Carbon::setTestNow();
    }

    #[Test]
    public function moderator_cannot_open_investor_pitch(): void
    {
        Role::findOrCreate('moderator');
        $user = User::factory()->create();
        $user->assignRole('moderator');

        $this->actingAs($user)
            ->get(route('admin.analytics.investor-pitch'))
            ->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function seedOrder(array $data): Order
    {
        /** @var Product $product */
        $product = $data['product'];

        $order = Order::query()->create([
            'order_number' => 'IP-'.uniqid(),
            'name' => 'Buyer',
            'phone' => '0171'.random_int(1000000, 9999999),
            'address' => 'Addr',
            'city' => $data['city'],
            'status' => $data['status'],
            'subtotal' => $data['total'] - $data['delivery_charge'],
            'delivery_charge' => $data['delivery_charge'],
            'courier_charge' => $data['courier_charge'],
            'packaging_cost' => 0,
            'discount' => 0,
            'total' => $data['total'],
            'collected_amount' => $data['collected_amount'],
            'paid_amount' => $data['collected_amount'],
            'due_amount' => 0,
            'payment_status' => $data['collected_amount'] > 0 ? 'paid' : 'unpaid',
            'payment_method' => 'cod',
            'placed_at' => $data['placed_at'],
            'actual_delivery_date' => $data['status'] === 'delivered' ? $data['placed_at'] : null,
            'placed_via' => $data['placed_via'],
        ]);

        OrderProduct::query()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'name' => $product->name,
            'quantity' => 1,
            'price' => $data['price'],
            'purchase_price' => $data['purchase_price'],
            'line_total' => $data['price'],
        ]);

        return $order;
    }
}
