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

class AdminProductsCopyPublicLinkTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function products_list_shows_copy_public_link_with_storefront_url(): void
    {
        Role::findOrCreate('admin');
        $user = User::factory()->create();
        $user->assignRole('admin');
        $this->actingAs($user);

        $product = Product::query()->create([
            'name' => 'Gold Jhumka',
            'slug' => 'gold-jhumka',
            'sku' => 'GJ-1',
            'price' => 1200,
            'is_published' => true,
            'display_order' => 1,
        ]);

        $publicUrl = route('product.show', $product);

        Livewire::test(AdminProducts::class)
            ->assertSee('Gold Jhumka')
            ->assertSee('Copy public link')
            ->assertSeeHtml('data-copy-public-link')
            ->assertSeeHtml('data-copy-text="'.$publicUrl.'"');

        $this->assertStringContainsString('/product/gold-jhumka', $publicUrl);
    }
}
