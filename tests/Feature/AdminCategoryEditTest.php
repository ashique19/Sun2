<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminCategoryEdit;
use App\Livewire\Admin\AdminProductEdit;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminCategoryEditTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function category(string $name = 'Earrings'): Category
    {
        return Category::query()->create([
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'is_active' => true,
            'is_homepage' => true,
            'display_order' => 0,
        ]);
    }

    #[Test]
    public function admin_can_open_category_index_create_and_edit_pages(): void
    {
        $this->actingAs($this->adminUser());
        $category = $this->category();

        $this->get(route('admin.categories'))
            ->assertOk()
            ->assertSee('Create Category')
            ->assertSee($category->name)
            ->assertSee('Edit');

        $this->get(route('admin.categories.create'))
            ->assertOk()
            ->assertSee('Create Category');

        $this->get(route('admin.categories.edit', $category))
            ->assertOk()
            ->assertSee('Save Category')
            ->assertSee($category->name);
    }

    #[Test]
    public function admin_can_create_a_category(): void
    {
        $this->actingAs($this->adminUser());

        Livewire::test(AdminCategoryEdit::class)
            ->set('name', 'Necklaces')
            ->set('slug', 'necklaces')
            ->set('is_active', true)
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('admin.categories.edit', 'necklaces'));

        $this->assertDatabaseHas('categories', [
            'name' => 'Necklaces',
            'slug' => 'necklaces',
            'is_active' => true,
        ]);
    }

    #[Test]
    public function admin_can_edit_a_category(): void
    {
        $this->actingAs($this->adminUser());
        $category = $this->category('Rings');

        Livewire::test(AdminCategoryEdit::class, ['category' => $category])
            ->set('name', 'Gold Rings')
            ->set('headline', 'Handcrafted rings')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('message', 'Category saved.');

        $this->assertDatabaseHas('categories', [
            'id' => $category->id,
            'name' => 'Gold Rings',
            'slug' => 'rings',
            'headline' => 'Handcrafted rings',
        ]);
    }

    #[Test]
    public function product_form_can_create_a_category_and_select_it(): void
    {
        $this->actingAs($this->adminUser());

        $component = Livewire::test(AdminProductEdit::class)
            ->assertSee('Create category')
            ->set('newCategoryName', 'Bracelets')
            ->call('createCategory')
            ->assertHasNoErrors();

        $category = Category::query()->where('slug', 'bracelets')->first();
        $this->assertNotNull($category);
        $this->assertSame('Bracelets', $category->name);
        $component->assertSet('category_id', $category->id);
        $component->assertSee('Edit category');
    }

    #[Test]
    public function product_form_links_to_edit_the_selected_category(): void
    {
        $this->actingAs($this->adminUser());
        $category = $this->category('Earrings');
        $product = Product::query()->create([
            'name' => 'Hoop',
            'slug' => 'hoop',
            'price' => 800,
            'category_id' => $category->id,
            'is_published' => true,
            'display_order' => 0,
        ]);

        Livewire::test(AdminProductEdit::class, ['product' => $product])
            ->assertSee('Edit category')
            ->assertSee(route('admin.categories.edit', $category), false);
    }
}
