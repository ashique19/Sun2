<?php

namespace Tests\Feature;

use App\Livewire\PublicInvestorPitch;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PublicInvestorPitchTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function share_route_is_not_found_when_unconfigured(): void
    {
        config([
            'investor_pitch.share_token' => '',
            'investor_pitch.share_password' => '',
        ]);

        $this->get(route('share.investor-pitch', ['token' => 'any-token-here-xx']))
            ->assertNotFound();
    }

    #[Test]
    public function wrong_token_returns_not_found(): void
    {
        config([
            'investor_pitch.share_token' => 'correct-share-token',
            'investor_pitch.share_password' => 'secret-pass',
        ]);

        $this->get(route('share.investor-pitch', ['token' => 'wrong-share-tokenx']))
            ->assertNotFound();
    }

    #[Test]
    public function guests_see_password_gate_then_unlock_deck(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-11 12:00:00', 'Asia/Dhaka'));

        config([
            'investor_pitch.share_token' => 'pitch-token-abcdef',
            'investor_pitch.share_password' => 'deck-password',
        ]);

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

        $this->get(route('share.investor-pitch', ['token' => 'pitch-token-abcdef']))
            ->assertOk()
            ->assertSee('Enter the share password')
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false)
            ->assertDontSee('Placed GMV')
            ->assertDontSee('Analytics hub')
            ->assertDontSee(route('admin.analytics'));

        Livewire::test(PublicInvestorPitch::class, ['token' => 'pitch-token-abcdef'])
            ->assertSet('unlocked', false)
            ->set('password', 'wrong')
            ->call('unlock')
            ->assertHasErrors('password')
            ->assertSet('unlocked', false)
            ->set('password', 'deck-password')
            ->call('unlock')
            ->assertHasNoErrors()
            ->assertSet('unlocked', true)
            ->assertSee('Investor pitch deck')
            ->assertSee('Placed GMV')
            ->assertSee('Sundoritoma')
            ->assertSee('Methodology notes')
            ->assertDontSee('Open P&L')
            ->assertDontSee('Refresh now')
            ->call('selectYear', 2026)
            ->assertSet('year', 2026)
            ->call('lock')
            ->assertSet('unlocked', false)
            ->assertSee('Enter the share password');

        Carbon::setTestNow();
    }

    #[Test]
    public function admin_page_shows_share_url_when_configured(): void
    {
        config([
            'investor_pitch.share_token' => 'pitch-token-abcdef',
            'investor_pitch.share_password' => 'deck-password',
        ]);

        Role::findOrCreate('admin');
        $user = User::factory()->create();
        $user->assignRole('admin');

        $this->actingAs($user)
            ->get(route('admin.analytics.investor-pitch'))
            ->assertOk()
            ->assertSee('Password-protected share link is live')
            ->assertSee(route('share.investor-pitch', ['token' => 'pitch-token-abcdef']), false)
            ->assertDontSee('Refresh now');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function seedOrder(array $data): Order
    {
        /** @var Product $product */
        $product = $data['product'];

        $order = Order::query()->create([
            'order_number' => 'PIP-'.uniqid(),
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
