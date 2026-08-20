<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminProducts;
use App\Models\Product;
use App\Models\SharedCart;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminProductsShareCartTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function admin_can_create_shareable_cart_from_selected_products(): void
    {
        Role::findOrCreate('admin');
        $user = User::factory()->create();
        $user->assignRole('admin');
        $this->actingAs($user);

        $published = Product::query()->create([
            'name' => 'Shareable Ring',
            'slug' => 'shareable-ring',
            'sku' => 'SR-1',
            'price' => 850,
            'is_published' => true,
            'display_order' => 1,
        ]);

        Product::query()->create([
            'name' => 'Hidden Necklace',
            'slug' => 'hidden-necklace',
            'sku' => 'HN-1',
            'price' => 1200,
            'is_published' => false,
            'display_order' => 2,
        ]);

        Livewire::test(AdminProducts::class)
            ->call('toggleSelected', $published->id)
            ->call('toggleSelected', Product::query()->where('slug', 'hidden-necklace')->value('id'))
            ->call('openShareCartModal')
            ->assertSet('shareCartModalOpen', true)
            ->assertCount('shareCartRows', 1)
            ->call('confirmShareCart')
            ->assertHasNoErrors()
            ->assertSet('shareCartModalOpen', false);

        $share = SharedCart::query()->first();

        $this->assertNotNull($share);
        $this->assertSame($user->id, $share->created_by);
        $this->assertCount(1, $share->items);
        $this->assertSame($published->id, $share->items[0]['product_id']);
        $this->assertSame(1, $share->items[0]['quantity']);
        $this->assertTrue($share->expires_at->isFuture());
    }

    #[Test]
    public function admin_can_set_quantities_before_sharing_cart(): void
    {
        Role::findOrCreate('admin');
        $user = User::factory()->create();
        $user->assignRole('admin');
        $this->actingAs($user);

        $ring = Product::query()->create([
            'name' => 'Shareable Ring',
            'slug' => 'shareable-ring-qty',
            'sku' => 'SR-2',
            'price' => 850,
            'is_published' => true,
            'display_order' => 1,
        ]);

        $bangle = Product::query()->create([
            'name' => 'Shareable Bangle',
            'slug' => 'shareable-bangle-qty',
            'sku' => 'SB-1',
            'price' => 1200,
            'is_published' => true,
            'display_order' => 2,
        ]);

        Livewire::test(AdminProducts::class)
            ->call('toggleSelected', $ring->id)
            ->call('toggleSelected', $bangle->id)
            ->call('openShareCartModal')
            ->set('shareCartQuantities.'.$ring->id, 3)
            ->set('shareCartQuantities.'.$bangle->id, 2)
            ->call('confirmShareCart')
            ->assertHasNoErrors();

        $share = SharedCart::query()->first();

        $this->assertNotNull($share);
        $itemsByProduct = collect($share->items)->keyBy('product_id')->all();
        $this->assertSame(3, $itemsByProduct[$ring->id]['quantity']);
        $this->assertSame(2, $itemsByProduct[$bangle->id]['quantity']);
    }

    #[Test]
    public function share_cart_modal_rejects_invalid_quantities(): void
    {
        Role::findOrCreate('admin');
        $user = User::factory()->create();
        $user->assignRole('admin');
        $this->actingAs($user);

        $product = Product::query()->create([
            'name' => 'Shareable Ring',
            'slug' => 'shareable-ring-invalid-qty',
            'sku' => 'SR-3',
            'price' => 850,
            'is_published' => true,
            'display_order' => 1,
        ]);

        Livewire::test(AdminProducts::class)
            ->call('toggleSelected', $product->id)
            ->call('openShareCartModal')
            ->set('shareCartQuantities.'.$product->id, 0)
            ->call('confirmShareCart')
            ->assertHasErrors(['shareCartQuantities.'.$product->id]);

        $this->assertSame(0, SharedCart::query()->count());
    }

    #[Test]
    public function products_page_shows_plus_cart_button(): void
    {
        Role::findOrCreate('admin');
        $user = User::factory()->create();
        $user->assignRole('admin');
        $this->actingAs($user);

        Livewire::test(AdminProducts::class)
            ->assertSee('+Cart (0)');
    }
}
