<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminProducts;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminProductBulkCategoryTest extends TestCase
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

    private function product(string $name, ?Category $category = null): Product
    {
        return Product::query()->create([
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'price' => 500,
            'stock_quantity' => 1,
            'category_id' => $category?->id,
            'is_published' => true,
            'display_order' => 0,
        ]);
    }

    #[Test]
    public function products_list_exposes_change_category_multiselect_action(): void
    {
        $this->actingAs($this->adminUser());
        $this->product('Ring');

        Livewire::test(AdminProducts::class)
            ->assertSee('Change category (0)')
            ->assertSeeHtml('wire:click="openBulkCategory"')
            ->assertDontSeeHtml('id="bulk-category-id"');
    }

    #[Test]
    public function admin_can_set_category_for_selected_products(): void
    {
        $this->actingAs($this->adminUser());
        $earrings = $this->category('Earrings');
        $necklaces = $this->category('Necklaces');

        $first = $this->product('First SKU', $earrings);
        $second = $this->product('Second SKU', $earrings);
        $untouched = $this->product('Leave Alone', $earrings);

        Livewire::test(AdminProducts::class)
            ->call('toggleSelected', $first->id)
            ->call('toggleSelected', $second->id)
            ->call('openBulkCategory')
            ->assertSet('bulkCategoryOpen', true)
            ->assertSee('New category for 2 selected')
            ->assertSeeHtml('id="bulk-category-id"')
            ->set('bulkCategoryId', (string) $necklaces->id)
            ->call('applyBulkCategory')
            ->assertHasNoErrors()
            ->assertSet('bulkCategoryOpen', false)
            ->assertSet('selected', [])
            ->assertSet('message', 'Category set to “Necklaces” for 2 products.');

        $this->assertSame($necklaces->id, (int) $first->fresh()->category_id);
        $this->assertSame($necklaces->id, (int) $second->fresh()->category_id);
        $this->assertSame($earrings->id, (int) $untouched->fresh()->category_id);
    }

    #[Test]
    public function bulk_category_requires_an_existing_category(): void
    {
        $this->actingAs($this->adminUser());
        $product = $this->product('Needs Category');

        Livewire::test(AdminProducts::class)
            ->call('toggleSelected', $product->id)
            ->call('openBulkCategory')
            ->set('bulkCategoryId', '')
            ->call('applyBulkCategory')
            ->assertHasErrors(['bulkCategoryId'])
            ->assertSet('bulkCategoryOpen', true);

        $this->assertNull($product->fresh()->category_id);

        Livewire::test(AdminProducts::class)
            ->call('toggleSelected', $product->id)
            ->call('openBulkCategory')
            ->set('bulkCategoryId', '999999')
            ->call('applyBulkCategory')
            ->assertHasErrors(['bulkCategoryId']);

        $this->assertNull($product->fresh()->category_id);
    }

    #[Test]
    public function open_bulk_category_does_nothing_without_selection(): void
    {
        $this->actingAs($this->adminUser());

        Livewire::test(AdminProducts::class)
            ->call('openBulkCategory')
            ->assertSet('bulkCategoryOpen', false);
    }

    #[Test]
    public function opening_bulk_category_closes_bulk_stock_panel(): void
    {
        $this->actingAs($this->adminUser());
        $category = $this->category('Rings');
        $product = $this->product('Toggle Panels', $category);

        Livewire::test(AdminProducts::class)
            ->call('toggleSelected', $product->id)
            ->call('openBulkStock')
            ->assertSet('bulkStockOpen', true)
            ->call('openBulkCategory')
            ->assertSet('bulkCategoryOpen', true)
            ->assertSet('bulkStockOpen', false);
    }
}
