<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminOrderForm;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminOrderFormProductSearchPaginationTest extends TestCase
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
     * @return list<Product>
     */
    private function matchingProducts(int $count): array
    {
        $products = [];

        for ($i = 1; $i <= $count; $i++) {
            $products[] = Product::query()->create([
                'name' => sprintf('Kurti Search %02d', $i),
                'slug' => sprintf('kurti-search-%02d', $i),
                'sku' => sprintf('KS%02d', $i),
                'price' => 500 + $i,
                'purchase_price' => 200,
                'stock_quantity' => 5,
                'is_published' => true,
                'display_order' => $i,
            ]);
        }

        return $products;
    }

    public function test_product_search_paginates_twenty_items_and_scrolls_to_list(): void
    {
        $this->actingAs($this->adminUser());
        $this->matchingProducts(21);

        $component = Livewire::test(AdminOrderForm::class)
            ->set('productSearch', 'Kurti')
            ->assertSee('Kurti Search 01')
            ->assertSee('Kurti Search 20')
            ->assertDontSee('Kurti Search 21')
            ->assertSeeHtml('id="admin-order-product-search-results"')
            ->assertSeeHtml('querySelector(&#039;#admin-order-product-search-results&#039;)')
            ->assertDontSeeHtml('querySelector(&#039;body&#039;)');

        $this->assertCount(
            AdminOrderForm::PRODUCT_SEARCH_PER_PAGE,
            $component->viewData('searchResults'),
        );
        $this->assertSame(21, $component->viewData('searchResults')->total());
        $this->assertTrue($component->viewData('searchResults')->hasPages());
        $this->assertSame(AdminOrderForm::PRODUCT_SEARCH_SCROLL_TO, '#admin-order-product-search-results');

        $html = $component->html();
        $this->assertStringContainsString('wire:click="nextPage(\'productPage\')"', $html);
        $this->assertStringContainsString('type="button"', $html);
        $this->assertStringContainsString(
            '($el.closest(&#039;#admin-order-product-search-results&#039;) || document.querySelector(&#039;#admin-order-product-search-results&#039;)).scrollIntoView()',
            $html,
        );
        $this->assertStringNotContainsString(
            '($el.closest(&#039;body&#039;) || document.querySelector(&#039;body&#039;)).scrollIntoView()',
            $html,
        );

        $component
            ->call('nextPage', 'productPage')
            ->assertDontSee('Kurti Search 01')
            ->assertSee('Kurti Search 21')
            ->assertSeeHtml('id="admin-order-product-search-results"')
            ->assertSeeHtml('querySelector(&#039;#admin-order-product-search-results&#039;)')
            ->assertDontSeeHtml('querySelector(&#039;body&#039;)');

        $this->assertCount(1, $component->viewData('searchResults'));
    }

    public function test_stock_filter_uses_same_page_size_and_list_scroll_target(): void
    {
        $this->actingAs($this->adminUser());
        $this->matchingProducts(21);

        $component = Livewire::test(AdminOrderForm::class)
            ->set('productStock', 'in')
            ->assertSee('Kurti Search 01')
            ->assertSee('Kurti Search 20')
            ->assertDontSee('Kurti Search 21')
            ->assertSeeHtml('id="admin-order-product-search-results"')
            ->assertSeeHtml('querySelector(&#039;#admin-order-product-search-results&#039;)');

        $this->assertCount(
            AdminOrderForm::PRODUCT_SEARCH_PER_PAGE,
            $component->viewData('searchResults'),
        );
    }
}
