<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminCategoryEdit;
use App\Livewire\Admin\AdminProductEdit;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Support\StorefrontAssets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
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

    #[Test]
    public function selecting_a_thumbnail_does_not_crash_the_edit_form(): void
    {
        $this->actingAs($this->adminUser());
        $category = $this->category('Thumb Preview');

        $html = Livewire::test(AdminCategoryEdit::class, ['category' => $category])
            ->set('thumbUpload', UploadedFile::fake()->image('preview.jpg', 400, 400))
            ->assertHasNoErrors()
            ->assertOk()
            ->html();

        $this->assertTrue(
            str_contains($html, 'alt="Category thumbnail"')
            || str_contains($html, 'Image selected. Save the category to store it.'),
            'Expected a thumbnail preview or a selected-image fallback after upload.'
        );
    }

    #[Test]
    public function admin_can_upload_a_category_thumbnail_on_create(): void
    {
        $this->actingAs($this->adminUser());

        Livewire::test(AdminCategoryEdit::class)
            ->set('name', 'Necklaces')
            ->set('slug', 'necklaces')
            ->set('is_active', true)
            ->set('thumbUpload', UploadedFile::fake()->image('necklace.jpg', 800, 600))
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('admin.categories.edit', 'necklaces'));

        $category = Category::query()->where('slug', 'necklaces')->first();
        $this->assertNotNull($category);
        $this->assertNotNull($category->thumb_image);
        $this->assertStringStartsWith('/img/categories/'.$category->id.'/', $category->thumb_image);
        $this->assertStringEndsWith('.jpg', $category->thumb_image);

        $absolute = public_path(ltrim($category->thumb_image, '/'));
        $this->assertFileExists($absolute);

        $info = getimagesize($absolute);
        $this->assertNotFalse($info);
        $this->assertSame(600, $info[0]);
        $this->assertSame(450, $info[1]);

        $base = preg_replace('/\.jpg$/i', '', $absolute);
        $this->assertFileExists($base.'_sm.jpg');
        $this->assertFileExists($base.'_xs.jpg');

        $this->assertStringNotContainsString('sundoritoma.com', (string) StorefrontAssets::url($category->thumb_image));
        $small = StorefrontAssets::smallUrl($category->thumb_image);
        $this->assertNotNull($small);
        $this->assertStringNotContainsString('sundoritoma.com', $small);
        $this->assertStringContainsString('_sm.jpg', $small);

        $this->get('/')
            ->assertOk()
            ->assertSee($category->name)
            ->assertSee('_sm.jpg', false);

        File::deleteDirectory(dirname($absolute));
    }

    #[Test]
    public function admin_can_replace_and_clear_a_category_thumbnail(): void
    {
        $this->actingAs($this->adminUser());
        $category = $this->category('Rings');

        Livewire::test(AdminCategoryEdit::class, ['category' => $category])
            ->set('thumbUpload', UploadedFile::fake()->image('rings.jpg', 640, 640))
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('message', 'Category saved.');

        $category->refresh();
        $this->assertNotNull($category->thumb_image);
        $first = public_path(ltrim($category->thumb_image, '/'));
        $this->assertFileExists($first);
        $firstSm = preg_replace('/\.jpg$/i', '_sm.jpg', $first);
        $this->assertFileExists($firstSm);

        Livewire::test(AdminCategoryEdit::class, ['category' => $category->fresh()])
            ->call('clearThumbnail')
            ->call('save')
            ->assertHasNoErrors();

        $category->refresh();
        $this->assertNull($category->thumb_image);
        $this->assertFileDoesNotExist($first);
        $this->assertFileDoesNotExist($firstSm);

        File::deleteDirectory(dirname($first));
    }

    #[Test]
    public function clearing_a_thumb_does_not_delete_shared_category_catalog_files(): void
    {
        $this->actingAs($this->adminUser());
        $shared = public_path('img/categories/Earring.jpg');
        $this->assertFileExists($shared);

        $category = $this->category('Earrings');
        $category->update(['thumb_image' => '/img/categories/Earring.jpg']);

        Livewire::test(AdminCategoryEdit::class, ['category' => $category->fresh()])
            ->call('clearThumbnail')
            ->call('save')
            ->assertHasNoErrors();

        $category->refresh();
        $this->assertNull($category->thumb_image);
        $this->assertFileExists($shared);
    }
}
