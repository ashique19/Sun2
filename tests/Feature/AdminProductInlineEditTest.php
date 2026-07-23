<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminProducts;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminProductInlineEditTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function product(): Product
    {
        return Product::query()->create([
            'name' => 'Gold plated jhumka',
            'slug' => 'gold-plated-jhumka',
            'sku' => 'GPJ-1',
            'price' => 980,
            'purchase_price' => 400,
            'stock_quantity' => 10,
            'is_published' => true,
            'display_order' => 0,
        ]);
    }

    #[Test]
    public function admin_can_inline_edit_price_cost_and_stock_from_product_list(): void
    {
        $this->actingAs($this->adminUser());
        $product = $this->product();

        Livewire::test(AdminProducts::class)
            ->call('startInlineEdit', $product->id, 'price', '980')
            ->assertSet('editingProductId', $product->id)
            ->assertSet('editingField', 'price')
            ->set('editingValue', '1250')
            ->call('saveInlineEdit')
            ->assertSet('editingProductId', null);

        $this->assertSame(1250.0, (float) $product->fresh()->price);

        Livewire::test(AdminProducts::class)
            ->call('startInlineEdit', $product->id, 'purchase_price', '400')
            ->set('editingValue', '525')
            ->call('saveInlineEdit');

        $this->assertSame(525.0, (float) $product->fresh()->purchase_price);

        Livewire::test(AdminProducts::class)
            ->call('startInlineEdit', $product->id, 'stock_quantity', '10')
            ->set('editingValue', '3')
            ->call('saveInlineEdit');

        $this->assertSame(3, (int) $product->fresh()->stock_quantity);
    }

    #[Test]
    public function inline_edit_rejects_negative_values_and_unknown_fields(): void
    {
        $this->actingAs($this->adminUser());
        $product = $this->product();

        Livewire::test(AdminProducts::class)
            ->call('startInlineEdit', $product->id, 'price', '980')
            ->set('editingValue', '-5')
            ->call('saveInlineEdit')
            ->assertHasErrors(['editingValue']);

        $this->assertSame(980.0, (float) $product->fresh()->price);

        Livewire::test(AdminProducts::class)
            ->call('startInlineEdit', $product->id, 'name', 'Nope')
            ->assertSet('editingProductId', null)
            ->assertSet('editingField', null);
    }

    #[Test]
    public function escape_cancels_inline_edit_without_saving(): void
    {
        $this->actingAs($this->adminUser());
        $product = $this->product();

        Livewire::test(AdminProducts::class)
            ->call('startInlineEdit', $product->id, 'stock_quantity', '10')
            ->set('editingValue', '99')
            ->call('cancelInlineEdit')
            ->assertSet('editingProductId', null);

        $this->assertSame(10, (int) $product->fresh()->stock_quantity);
    }
}
