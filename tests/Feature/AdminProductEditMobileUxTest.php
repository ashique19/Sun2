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

class AdminProductEditMobileUxTest extends TestCase
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
    public function edit_page_puts_images_first_and_floats_actions_on_small_screens(): void
    {
        $this->actingAs($this->adminUser());

        $product = Product::query()->create([
            'name' => 'Mobile Edit Product',
            'slug' => 'mobile-edit-product',
            'price' => 500,
            'is_published' => true,
        ]);

        Livewire::test(AdminProductEdit::class, ['product' => $product])
            ->assertSeeHtml('data-product-edit-images')
            ->assertSeeHtml('order-1 rounded-xl border border-[#EFE7D6] bg-white p-6 space-y-6 md:order-3')
            ->assertSeeHtml('data-product-edit-details')
            ->assertSeeHtml('order-2 rounded-xl border border-[#EFE7D6] bg-white p-6 space-y-4 md:order-1')
            ->assertSeeHtml('data-product-edit-actions')
            ->assertSeeHtml('fixed inset-x-0 bottom-0')
            ->assertSeeHtml('md:static')
            ->assertSeeHtml('flex-[2]')
            ->assertSeeHtml('flex-1 rounded-full border border-rose-300')
            ->assertSee('Save Product')
            ->assertSee('Delete');
    }

    #[Test]
    public function save_message_renders_as_toast_and_can_be_dismissed(): void
    {
        $this->actingAs($this->adminUser());

        $product = Product::query()->create([
            'name' => 'Toast Product',
            'slug' => 'toast-product',
            'price' => 700,
            'is_published' => true,
        ]);

        Livewire::test(AdminProductEdit::class, ['product' => $product])
            ->call('save')
            ->assertSet('message', 'Product saved.')
            ->assertSeeHtml('data-product-edit-toast')
            ->assertSee('Product saved.')
            ->assertDontSeeHtml('rounded-lg bg-emerald-50 text-emerald-700 text-sm px-4 py-3 mb-4')
            ->call('dismissMessage')
            ->assertSet('message', null);
    }
}
