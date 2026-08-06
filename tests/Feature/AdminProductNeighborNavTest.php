<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminProductEdit;
use App\Livewire\Admin\AdminProductShow;
use App\Models\Product;
use App\Models\User;
use App\Support\AdminProductNavigator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminProductNeighborNavTest extends TestCase
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
        // Admin list: display_order ASC, id DESC
        // Desired list order: first, middle, last
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

        // Same display_order as middle, but higher id → appears before middle.
        $this->assertGreaterThan($middle->id, $first->id);

        return [$first, $middle, $last];
    }

    #[Test]
    public function navigator_follows_admin_list_order(): void
    {
        [$first, $middle, $last] = $this->orderedProducts();

        $this->assertNull(AdminProductNavigator::previous($first));
        $this->assertSame($middle->id, AdminProductNavigator::next($first)?->id);

        $this->assertSame($first->id, AdminProductNavigator::previous($middle)?->id);
        $this->assertSame($last->id, AdminProductNavigator::next($middle)?->id);

        $this->assertSame($middle->id, AdminProductNavigator::previous($last)?->id);
        $this->assertNull(AdminProductNavigator::next($last));
    }

    #[Test]
    public function product_show_page_links_to_previous_and_next_show_routes(): void
    {
        $this->actingAs($this->adminUser());
        [$first, $middle, $last] = $this->orderedProducts();

        Livewire::test(AdminProductShow::class, ['product' => $middle])
            ->assertSeeHtml('aria-label="Previous product"')
            ->assertSeeHtml('aria-label="Next product"')
            ->assertSeeHtml(route('admin.products.show', $first))
            ->assertSeeHtml(route('admin.products.show', $last));

        Livewire::test(AdminProductShow::class, ['product' => $first])
            ->assertSeeHtml('aria-label="No previous product"')
            ->assertSeeHtml('aria-label="Next product"')
            ->assertSeeHtml(route('admin.products.show', $middle))
            ->assertDontSeeHtml(route('admin.products.show', $last));
    }

    #[Test]
    public function product_edit_page_links_to_previous_and_next_edit_routes(): void
    {
        $this->actingAs($this->adminUser());
        [$first, $middle, $last] = $this->orderedProducts();

        Livewire::test(AdminProductEdit::class, ['product' => $middle])
            ->assertSeeHtml('aria-label="Previous product"')
            ->assertSeeHtml('aria-label="Next product"')
            ->assertSeeHtml(route('admin.products.edit', $first))
            ->assertSeeHtml(route('admin.products.edit', $last));
    }

    #[Test]
    public function create_product_page_does_not_show_neighbor_nav(): void
    {
        $this->actingAs($this->adminUser());
        $this->orderedProducts();

        Livewire::test(AdminProductEdit::class)
            ->assertDontSeeHtml('aria-label="Previous product"')
            ->assertDontSeeHtml('aria-label="Next product"')
            ->assertDontSeeHtml('aria-label="Product navigation"');
    }
}
