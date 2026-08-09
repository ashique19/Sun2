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

class AdminProductNeighborSwipeTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    /**
     * @return array{0: Product, 1: Product, 2: Product}
     */
    private function orderedProducts(): array
    {
        $last = Product::query()->create([
            'name' => 'Last Product',
            'slug' => 'last-product',
            'price' => 300,
            'display_order' => 2,
            'is_published' => true,
        ]);
        $middle = Product::query()->create([
            'name' => 'Middle Product',
            'slug' => 'middle-product',
            'price' => 200,
            'display_order' => 1,
            'is_published' => true,
        ]);
        $first = Product::query()->create([
            'name' => 'First Product',
            'slug' => 'first-product',
            'price' => 100,
            'display_order' => 1,
            'is_published' => true,
        ]);

        return [$first, $middle, $last];
    }

    #[Test]
    public function product_show_exposes_swipe_neighbor_urls_on_small_screens(): void
    {
        $this->actingAs($this->adminUser());
        [$first, $middle, $last] = $this->orderedProducts();

        $html = Livewire::test(AdminProductShow::class, ['product' => $middle])
            ->assertSeeHtml('data-product-swipe-nav')
            ->assertSeeHtml('@touchstart.window.passive')
            ->assertSeeHtml('@touchend.window.passive')
            ->assertSeeHtml('data-previous-url="'.e(route('admin.products.show', $first)).'"')
            ->assertSeeHtml('data-next-url="'.e(route('admin.products.show', $last)).'"')
            ->assertSeeHtml('max-width: 767px')
            ->assertSeeHtml("getAttribute('data-previous-url')")
            ->html();

        // Alpine must stay in attributes — broken quoting used to dump JS into visible text.
        $visible = html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8');
        $this->assertStringNotContainsString('window.Livewire.navigate', $visible);
        $this->assertStringNotContainsString('onStart($event)', $visible);
    }

    #[Test]
    public function product_edit_exposes_swipe_neighbor_urls(): void
    {
        $this->actingAs($this->adminUser());
        [$first, $middle, $last] = $this->orderedProducts();

        Livewire::test(AdminProductEdit::class, ['product' => $middle])
            ->assertSeeHtml('data-product-swipe-nav')
            ->assertSeeHtml('data-previous-url="'.route('admin.products.edit', $first).'"')
            ->assertSeeHtml('data-next-url="'.route('admin.products.edit', $last).'"');
    }
}
