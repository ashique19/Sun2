<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminAnalyticsInvestorPitch;
use App\Livewire\PublicInvestorPitch;
use App\Models\Category;
use App\Models\InvestorPitchShare;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PublicInvestorPitchTest extends TestCase
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
    public function create_share_auto_creates_missing_table(): void
    {
        Schema::dropIfExists('investor_pitch_shares');
        $this->assertFalse(Schema::hasTable('investor_pitch_shares'));

        $this->actingAs($this->adminUser());

        Livewire::test(AdminAnalyticsInvestorPitch::class)
            ->set('shareLabel', 'Auto schema')
            ->set('sharePassword', 'investor-secret')
            ->set('shareDays', 7)
            ->call('createShare')
            ->assertHasNoErrors()
            ->assertRedirect(route('admin.analytics.investor-pitch', ['year' => (int) now('Asia/Dhaka')->year]));

        $this->assertTrue(Schema::hasTable('investor_pitch_shares'));
        $this->assertDatabaseHas('investor_pitch_shares', [
            'label' => 'Auto schema',
        ]);
        $this->assertSame('investor-secret', session('investor_pitch_share_created.password'));
    }

    #[Test]
    public function missing_token_returns_not_found(): void
    {
        $this->get(route('share.investor-pitch', ['token' => str_repeat('a', 48)]))
            ->assertNotFound();
    }

    #[Test]
    public function admin_can_create_share_link_with_password_and_days(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-12 10:00:00', 'Asia/Dhaka'));

        $this->actingAs($this->adminUser());

        Livewire::test(AdminAnalyticsInvestorPitch::class)
            ->set('shareLabel', 'Acme Ventures')
            ->set('sharePassword', 'investor-secret')
            ->set('shareDays', 14)
            ->call('createShare')
            ->assertHasNoErrors()
            ->assertRedirect(route('admin.analytics.investor-pitch', ['year' => 2026]));

        $share = InvestorPitchShare::query()->first();
        $this->assertNotNull($share);
        $this->assertSame('Acme Ventures', $share->label);
        $this->assertTrue($share->passwordMatches('investor-secret'));
        $this->assertFalse($share->passwordMatches('wrong'));
        $this->assertTrue($share->isAccessible());
        $this->assertSame(
            Carbon::parse('2026-08-26 10:00:00', 'Asia/Dhaka')->timestamp,
            $share->expires_at->timestamp,
        );

        $flash = session('investor_pitch_share_created');
        $this->assertIsArray($flash);
        $this->assertSame('investor-secret', $flash['password']);
        $this->assertSame('Acme Ventures', $flash['label']);
        $this->assertStringContainsString($share->token, $flash['url']);

        // Follow the redirect: flash is pulled into component state for one-time display.
        session()->flash('investor_pitch_share_created', $flash);

        Livewire::test(AdminAnalyticsInvestorPitch::class)
            ->assertSet('createdSharePassword', 'investor-secret')
            ->assertSet('createdShareLabel', 'Acme Ventures')
            ->assertSee('Link ready')
            ->assertSee('Acme Ventures')
            ->assertSee('Copy link')
            ->assertDontSee('Refresh now');

        Carbon::setTestNow();
    }

    #[Test]
    public function guests_see_password_gate_then_unlock_deck(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-11 12:00:00', 'Asia/Dhaka'));

        $share = InvestorPitchShare::query()->create([
            'token' => str_repeat('b', 48),
            'label' => 'Seed fund',
            'password' => 'deck-password',
            'expires_at' => now()->addDays(7),
            'created_by' => null,
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

        $this->get(route('share.investor-pitch', ['token' => $share->token]))
            ->assertOk()
            ->assertSee('Enter the share password')
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false)
            ->assertDontSee('Placed GMV')
            ->assertDontSee(route('admin.analytics'));

        Livewire::test(PublicInvestorPitch::class, ['token' => $share->token])
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
            ->call('lock')
            ->assertSet('unlocked', false);

        Carbon::setTestNow();
    }

    #[Test]
    public function expired_and_revoked_links_block_access(): void
    {
        $expired = InvestorPitchShare::query()->create([
            'token' => str_repeat('c', 48),
            'label' => 'Expired',
            'password' => 'deck-password',
            'expires_at' => now()->subDay(),
        ]);

        $revoked = InvestorPitchShare::query()->create([
            'token' => str_repeat('d', 48),
            'label' => 'Revoked',
            'password' => 'deck-password',
            'expires_at' => now()->addDays(7),
            'revoked_at' => now(),
        ]);

        $this->get(route('share.investor-pitch', ['token' => $expired->token]))
            ->assertOk()
            ->assertSee('This share link has expired')
            ->assertDontSee('Unlock deck');

        $this->get(route('share.investor-pitch', ['token' => $revoked->token]))
            ->assertOk()
            ->assertSee('This share link was revoked')
            ->assertDontSee('Unlock deck');

        Livewire::test(PublicInvestorPitch::class, ['token' => $expired->token])
            ->set('password', 'deck-password')
            ->call('unlock')
            ->assertSet('unlocked', false)
            ->assertSee('This share link has expired');
    }

    #[Test]
    public function admin_can_revoke_share_link(): void
    {
        $this->actingAs($this->adminUser());

        $share = InvestorPitchShare::query()->create([
            'token' => str_repeat('e', 48),
            'label' => 'To revoke',
            'password' => 'deck-password',
            'expires_at' => now()->addDays(3),
            'created_by' => auth()->id(),
        ]);

        Livewire::test(AdminAnalyticsInvestorPitch::class)
            ->assertSee('To revoke')
            ->assertSee('Active')
            ->call('revokeShare', $share->id)
            ->assertSee('Revoked');

        $this->assertTrue($share->fresh()->isRevoked());
        $this->assertFalse($share->fresh()->isAccessible());
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
