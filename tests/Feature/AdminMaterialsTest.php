<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminMaterialEdit;
use App\Livewire\Admin\AdminMaterials;
use App\Livewire\Admin\AdminProductEdit;
use App\Models\Material;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminMaterialsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        Role::findOrCreate('admin');
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    #[Test]
    public function admin_can_create_material_and_receive_stock(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(AdminMaterialEdit::class)
            ->set('name', 'Cotton')
            ->set('sku', 'COT-1')
            ->set('unit', 'm')
            ->set('unit_cost', '40')
            ->set('stock_quantity', '5')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('message', 'Material saved.');

        $material = Material::query()->first();
        $this->assertNotNull($material);
        $this->assertSame('Cotton', $material->name);

        Livewire::test(AdminMaterialEdit::class, ['material' => $material])
            ->set('receive_quantity', '5')
            ->set('receive_total_cost', '300')
            ->call('receiveStock')
            ->assertHasNoErrors();

        $material->refresh();
        $this->assertSame(10.0, (float) $material->stock_quantity);
        $this->assertSame(50.0, (float) $material->unit_cost); // (5*40 + 300) / 10
    }

    #[Test]
    public function materials_index_lists_rows(): void
    {
        $this->actingAs($this->admin());
        Material::query()->create([
            'name' => 'Ribbon',
            'unit' => 'pcs',
            'unit_cost' => 5,
            'stock_quantity' => 20,
        ]);

        Livewire::test(AdminMaterials::class)
            ->assertSee('Ribbon')
            ->assertSee('Materials');
    }

    #[Test]
    public function product_edit_can_link_material_and_recalculate_unit_cost(): void
    {
        $this->actingAs($this->admin());

        $product = Product::query()->create([
            'name' => 'Kurti',
            'slug' => 'kurti-bom',
            'sku' => 'K1',
            'price' => 900,
            'purchase_price' => 200,
            'unit_cost' => 200,
            'stock_quantity' => 3,
            'is_published' => true,
            'display_order' => 0,
        ]);
        $material = Material::query()->create([
            'name' => 'Fabric',
            'unit' => 'm',
            'unit_cost' => 80,
            'stock_quantity' => 50,
        ]);

        Livewire::test(AdminProductEdit::class, ['product' => $product])
            ->set('bomMaterialId', $material->id)
            ->set('bomQuantity', '2.5')
            ->set('bomIsPrimary', true)
            ->call('addBomMaterial')
            ->assertHasNoErrors()
            ->assertSet('purchase_price', '200')
            ->assertSet('unit_cost_display', '200');

        // 2.5 × 80 = 200
        $this->assertSame(200.0, (float) $product->fresh()->unit_cost);
        $this->assertTrue($product->materials()->where('materials.id', $material->id)->exists());
    }
}
