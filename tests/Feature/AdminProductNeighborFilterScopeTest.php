<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminProductEdit;
use App\Livewire\Admin\AdminProductShow;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Support\AdminProductListFilters;
use App\Support\AdminProductNavigator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminProductNeighborFilterScopeTest extends TestCase
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
     * @return array{rings: Category, publishedA: Product, draftB: Product, publishedC: Product}
     */
    private function seededCatalog(): array
    {
        $rings = Category::query()->create([
            'name' => 'Rings',
            'slug' => 'rings',
        ]);

        // List order: display_order ASC, id DESC
        $publishedC = Product::query()->create([
            'name' => 'Published C',
            'slug' => 'published-c',
            'sku' => 'PC',
            'price' => 300,
            'category_id' => $rings->id,
            'is_published' => true,
            'display_order' => 2,
        ]);
        $draftB = Product::query()->create([
            'name' => 'Draft B',
            'slug' => 'draft-b',
            'sku' => 'DB',
            'price' => 200,
            'category_id' => $rings->id,
            'is_published' => false,
            'display_order' => 1,
        ]);
        $publishedA = Product::query()->create([
            'name' => 'Published A',
            'slug' => 'published-a',
            'sku' => 'PA',
            'price' => 100,
            'category_id' => $rings->id,
            'is_published' => true,
            'display_order' => 1,
        ]);

        return [
            'rings' => $rings,
            'publishedA' => $publishedA,
            'draftB' => $draftB,
            'publishedC' => $publishedC,
        ];
    }

    #[Test]
    public function navigator_respects_published_filter_scope(): void
    {
        $catalog = $this->seededCatalog();

        $filters = AdminProductListFilters::fromArray(['published' => '1']);

        // Unfiltered: A → B → C
        $this->assertSame($catalog['draftB']->id, AdminProductNavigator::next($catalog['publishedA'], new AdminProductListFilters)?->id);

        // Published only: A → C (skips draft B)
        $this->assertSame($catalog['publishedC']->id, AdminProductNavigator::next($catalog['publishedA'], $filters)?->id);
        $this->assertSame($catalog['publishedA']->id, AdminProductNavigator::previous($catalog['publishedC'], $filters)?->id);
        $this->assertNull(AdminProductNavigator::next($catalog['publishedC'], $filters));
    }

    #[Test]
    public function product_show_next_prev_follow_session_filters(): void
    {
        $this->actingAs($this->adminUser());
        $catalog = $this->seededCatalog();

        AdminProductListFilters::fromArray(['published' => '1'])->remember();

        Livewire::test(AdminProductShow::class, ['product' => $catalog['publishedA']])
            ->assertSeeHtml('data-product-list-filters')
            ->assertSee('Search & filters')
            ->assertSeeHtml('data-next-url="'.route('admin.products.show', $catalog['publishedC']).'"')
            ->assertDontSeeHtml('data-next-url="'.route('admin.products.show', $catalog['draftB']).'"')
            ->assertSeeHtml(route('admin.products', ['published' => '1']));
    }

    #[Test]
    public function changing_filters_on_product_show_updates_neighbor_scope(): void
    {
        $this->actingAs($this->adminUser());
        $catalog = $this->seededCatalog();

        Livewire::test(AdminProductShow::class, ['product' => $catalog['publishedA']])
            ->set('published', '1')
            ->assertSeeHtml('data-next-url="'.route('admin.products.show', $catalog['publishedC']).'"')
            ->set('published', '')
            ->assertSeeHtml('data-next-url="'.route('admin.products.show', $catalog['draftB']).'"');
    }

    #[Test]
    public function product_edit_exposes_collapsed_filters_and_scoped_neighbors(): void
    {
        $this->actingAs($this->adminUser());
        $catalog = $this->seededCatalog();

        AdminProductListFilters::fromArray(['published' => '1'])->remember();

        Livewire::test(AdminProductEdit::class, ['product' => $catalog['publishedA']])
            ->assertSeeHtml('data-product-list-filters')
            ->assertSeeHtml('x-data="{ open: false }"')
            ->assertSeeHtml('data-next-url="'.route('admin.products.edit', $catalog['publishedC']).'"');
    }

    #[Test]
    public function create_product_page_does_not_show_filter_panel(): void
    {
        $this->actingAs($this->adminUser());

        Livewire::test(AdminProductEdit::class)
            ->assertDontSeeHtml('data-product-list-filters')
            ->assertDontSee('Search & filters');
    }
}
