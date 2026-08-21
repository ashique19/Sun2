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

class AdminProductSeoFieldsTest extends TestCase
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
    public function admin_can_save_product_meta_title_and_description(): void
    {
        $this->actingAs($this->adminUser());

        $product = Product::query()->create([
            'name' => 'Pearl Set',
            'slug' => 'pearl-set',
            'price' => 1200,
            'is_published' => true,
        ]);

        Livewire::test(AdminProductEdit::class, ['product' => $product])
            ->set('meta_title', 'Pearl Jewellery Set | Sundoritoma')
            ->set('meta_description', 'Handmade pearl set with cash on delivery across Bangladesh.')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'meta_title' => 'Pearl Jewellery Set | Sundoritoma',
            'meta_description' => 'Handmade pearl set with cash on delivery across Bangladesh.',
        ]);

        $this->get(route('product.show', $product->fresh()))
            ->assertOk()
            ->assertSee('<title>Pearl Jewellery Set | Sundoritoma</title>', false)
            ->assertSee('Handmade pearl set with cash on delivery across Bangladesh.', false);
    }

    #[Test]
    public function blank_meta_fields_are_stored_as_null(): void
    {
        $this->actingAs($this->adminUser());

        $product = Product::query()->create([
            'name' => 'Brass Bangle',
            'slug' => 'brass-bangle',
            'price' => 800,
            'meta_title' => 'Old Title',
            'meta_description' => 'Old description',
            'is_published' => true,
        ]);

        Livewire::test(AdminProductEdit::class, ['product' => $product])
            ->set('meta_title', '   ')
            ->set('meta_description', '')
            ->call('save')
            ->assertHasNoErrors();

        $product->refresh();

        $this->assertNull($product->meta_title);
        $this->assertNull($product->meta_description);
    }
}
