<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminProductEdit;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminProductCreateUniqueSlugTest extends TestCase
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
    public function create_auto_assigns_next_available_slug_when_slug_exists(): void
    {
        $this->actingAs($this->adminUser());

        Product::query()->create([
            'name' => 'Gold Necklace',
            'slug' => 'gold-necklace',
            'price' => 1500,
            'purchase_price' => 500,
            'stock_quantity' => 1,
            'is_published' => true,
            'display_order' => 0,
        ]);

        Livewire::test(AdminProductEdit::class)
            ->set('name', 'Gold Necklace Copy')
            ->set('slug', 'gold-necklace')
            ->set('price', '1600')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('products', [
            'name' => 'Gold Necklace Copy',
            'slug' => 'gold-necklace-1',
        ]);
    }

    #[Test]
    public function create_increments_past_existing_suffixed_slugs(): void
    {
        $this->actingAs($this->adminUser());

        foreach (['gold-necklace', 'gold-necklace-1', 'gold-necklace-2'] as $slug) {
            Product::query()->create([
                'name' => 'Existing '.$slug,
                'slug' => $slug,
                'price' => 1000,
                'purchase_price' => 400,
                'stock_quantity' => 1,
                'is_published' => true,
                'display_order' => 0,
            ]);
        }

        Livewire::test(AdminProductEdit::class)
            ->set('name', 'Another Gold Necklace')
            ->set('slug', 'gold-necklace')
            ->set('price', '1700')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('products', [
            'name' => 'Another Gold Necklace',
            'slug' => 'gold-necklace-3',
        ]);
    }

    #[Test]
    public function create_keeps_slug_when_it_is_free(): void
    {
        $this->actingAs($this->adminUser());

        Livewire::test(AdminProductEdit::class)
            ->set('name', 'Fresh Product')
            ->set('slug', 'fresh-product')
            ->set('price', '900')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('products', [
            'name' => 'Fresh Product',
            'slug' => 'fresh-product',
        ]);
    }

    #[Test]
    public function updated_name_on_create_fills_next_available_slug(): void
    {
        $this->actingAs($this->adminUser());

        Product::query()->create([
            'name' => 'Pearl Set',
            'slug' => 'pearl-set',
            'price' => 1200,
            'purchase_price' => 400,
            'stock_quantity' => 1,
            'is_published' => true,
            'display_order' => 0,
        ]);

        Livewire::test(AdminProductEdit::class)
            ->set('name', 'Pearl Set')
            ->assertSet('slug', 'pearl-set-1');
    }

    #[Test]
    public function edit_still_rejects_duplicate_slug(): void
    {
        $this->actingAs($this->adminUser());

        $existing = Product::query()->create([
            'name' => 'Taken Slug Product',
            'slug' => 'taken-slug',
            'price' => 1000,
            'purchase_price' => 400,
            'stock_quantity' => 1,
            'is_published' => true,
            'display_order' => 0,
        ]);

        $product = Product::query()->create([
            'name' => 'Other Product',
            'slug' => 'other-product',
            'price' => 1100,
            'purchase_price' => 400,
            'stock_quantity' => 1,
            'is_published' => true,
            'display_order' => 0,
        ]);

        Livewire::test(AdminProductEdit::class, ['product' => $product])
            ->set('slug', 'taken-slug')
            ->call('save')
            ->assertHasErrors(['slug']);

        $this->assertSame('taken-slug', $existing->fresh()->slug);
        $this->assertSame('other-product', $product->fresh()->slug);
    }
}
