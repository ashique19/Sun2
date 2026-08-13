<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminProductEdit;
use App\Livewire\Admin\AdminProductShow;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminProductStorefrontLinkTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function product(bool $published = true): Product
    {
        return Product::query()->create([
            'name' => 'Pearl Drop',
            'slug' => 'pearl-drop',
            'price' => 1100,
            'is_published' => $published,
        ]);
    }

    #[Test]
    public function product_show_links_to_storefront(): void
    {
        $this->actingAs($this->adminUser());
        $product = $this->product();
        $storeUrl = route('product.show', $product);

        Livewire::test(AdminProductShow::class, ['product' => $product])
            ->assertSee('View on store')
            ->assertSeeHtml('data-storefront-product-link')
            ->assertSeeHtml('href="'.$storeUrl.'"');
    }

    #[Test]
    public function product_edit_links_to_storefront_even_when_unpublished(): void
    {
        $this->actingAs($this->adminUser());
        $product = $this->product(published: false);
        $storeUrl = route('product.show', $product);

        Livewire::test(AdminProductEdit::class, ['product' => $product])
            ->assertSee('View on store')
            ->assertSeeHtml('data-storefront-product-link')
            ->assertSeeHtml('href="'.$storeUrl.'"');
    }
}
