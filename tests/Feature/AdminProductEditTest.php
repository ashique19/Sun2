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

class AdminProductEditTest extends TestCase
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
    public function admin_can_set_regular_price_greater_than_selling_price(): void
    {
        $this->actingAs($this->adminUser());
        $product = Product::query()->create([
            'name' => 'Test Product',
            'slug' => 'test-product',
            'price' => 1000,
            'is_published' => true,
        ]);

        Livewire::test(AdminProductEdit::class, ['product' => $product])
            ->set('compare_at_price', '1500')
            ->set('price', '1000')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'price' => 1000,
            'compare_at_price' => 1500,
        ]);
    }

    #[Test]
    public function regular_price_must_be_greater_than_selling_price(): void
    {
        $this->actingAs($this->adminUser());
        $product = Product::query()->create([
            'name' => 'Test Product',
            'slug' => 'test-product',
            'price' => 1000,
            'is_published' => true,
        ]);

        Livewire::test(AdminProductEdit::class, ['product' => $product])
            ->set('compare_at_price', '500') // Less than selling price
            ->set('price', '1000')
            ->call('save')
            ->assertHasErrors(['compare_at_price' => 'Regular price must be greater than selling price.']);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'price' => 1000,
            'compare_at_price' => null, // Should not be updated
        ]);
    }

    #[Test]
    public function regular_price_can_be_null(): void
    {
        $this->actingAs($this->adminUser());
        $product = Product::query()->create([
            'name' => 'Test Product',
            'slug' => 'test-product',
            'price' => 1000,
            'compare_at_price' => 1500,
            'is_published' => true,
        ]);

        Livewire::test(AdminProductEdit::class, ['product' => $product])
            ->set('compare_at_price', '')
            ->set('price', '1000')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'price' => 1000,
            'compare_at_price' => null,
        ]);
    }

    #[Test]
    public function regular_price_equal_to_selling_price_is_invalid(): void
    {
        $this->actingAs($this->adminUser());
        $product = Product::query()->create([
            'name' => 'Test Product',
            'slug' => 'test-product',
            'price' => 1000,
            'is_published' => true,
        ]);

        Livewire::test(AdminProductEdit::class, ['product' => $product])
            ->set('compare_at_price', '1000') // Equal to selling price
            ->set('price', '1000')
            ->call('save')
            ->assertHasErrors(['compare_at_price' => 'Regular price must be greater than selling price.']);
    }

    #[Test]
    public function admin_can_create_product_with_regular_price(): void
    {
        $this->actingAs($this->adminUser());

        Livewire::test(AdminProductEdit::class)
            ->set('name', 'Test Product')
            ->set('slug', 'test-product')
            ->set('price', '2000')
            ->set('compare_at_price', '2500')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('products', [
            'name' => 'Test Product',
            'slug' => 'test-product',
            'price' => 2000,
            'compare_at_price' => 2500,
        ]);
    }
}
