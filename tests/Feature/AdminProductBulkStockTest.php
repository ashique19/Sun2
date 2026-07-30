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

class AdminProductBulkStockTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function product(string $name, int $stock): Product
    {
        return Product::query()->create([
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'price' => 500,
            'stock_quantity' => $stock,
            'is_published' => true,
            'display_order' => 0,
        ]);
    }

    #[Test]
    public function products_list_exposes_change_stock_multiselect_action(): void
    {
        $this->actingAs($this->adminUser());
        $this->product('Ring', 2);

        Livewire::test(AdminProducts::class)
            ->assertSee('Change stock (0)')
            ->assertSeeHtml('wire:click="openBulkStock"')
            ->assertDontSeeHtml('id="bulk-stock-quantity"');
    }

    #[Test]
    public function admin_can_set_stock_for_selected_products(): void
    {
        $this->actingAs($this->adminUser());
        $first = $this->product('First SKU', 1);
        $second = $this->product('Second SKU', 4);
        $untouched = $this->product('Leave Alone', 9);

        Livewire::test(AdminProducts::class)
            ->call('toggleSelected', $first->id)
            ->call('toggleSelected', $second->id)
            ->call('openBulkStock')
            ->assertSet('bulkStockOpen', true)
            ->assertSee('New stock quantity for 2 selected')
            ->assertSeeHtml('id="bulk-stock-quantity"')
            ->set('bulkStockQuantity', '15')
            ->call('applyBulkStock')
            ->assertHasNoErrors()
            ->assertSet('bulkStockOpen', false)
            ->assertSet('selected', [])
            ->assertSet('message', 'Stock set to 15 for 2 products.');

        $this->assertSame(15, (int) $first->fresh()->stock_quantity);
        $this->assertSame(15, (int) $second->fresh()->stock_quantity);
        $this->assertSame(9, (int) $untouched->fresh()->stock_quantity);
    }

    #[Test]
    public function bulk_stock_requires_a_non_negative_integer(): void
    {
        $this->actingAs($this->adminUser());
        $product = $this->product('Needs Stock', 3);

        Livewire::test(AdminProducts::class)
            ->call('toggleSelected', $product->id)
            ->call('openBulkStock')
            ->set('bulkStockQuantity', '')
            ->call('applyBulkStock')
            ->assertHasErrors(['bulkStockQuantity'])
            ->assertSet('bulkStockOpen', true);

        $this->assertSame(3, (int) $product->fresh()->stock_quantity);

        Livewire::test(AdminProducts::class)
            ->call('toggleSelected', $product->id)
            ->call('openBulkStock')
            ->set('bulkStockQuantity', '-1')
            ->call('applyBulkStock')
            ->assertHasErrors(['bulkStockQuantity']);

        $this->assertSame(3, (int) $product->fresh()->stock_quantity);
    }

    #[Test]
    public function open_bulk_stock_does_nothing_without_selection(): void
    {
        $this->actingAs($this->adminUser());

        Livewire::test(AdminProducts::class)
            ->call('openBulkStock')
            ->assertSet('bulkStockOpen', false);
    }
}
