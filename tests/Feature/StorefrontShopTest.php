<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StorefrontShopTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function shop_is_indexable_with_canonical_and_lists_published_products(): void
    {
        Product::query()->create([
            'name' => 'Gold Ring',
            'slug' => 'gold-ring',
            'sku' => 'GR-1',
            'price' => 500,
            'purchase_price' => 200,
            'stock_quantity' => 3,
            'is_published' => true,
            'display_order' => 1,
        ]);

        Product::query()->create([
            'name' => 'Draft Ring',
            'slug' => 'draft-ring',
            'sku' => 'DR-1',
            'price' => 100,
            'purchase_price' => 40,
            'stock_quantity' => 1,
            'is_published' => false,
            'display_order' => 0,
        ]);

        $response = $this->get(route('shop'));

        $response->assertOk();
        $response->assertSee('Gold Ring');
        $response->assertDontSee('Draft Ring');
        $response->assertSee('<meta name="robots" content="index, follow">', false);
        $response->assertSee('<link rel="canonical" href="'.route('shop').'">', false);
        $response->assertSee(__('storefront.shop_all'), false);
    }

    #[Test]
    public function shop_facet_and_search_urls_are_noindex_with_clean_canonical(): void
    {
        Product::query()->create([
            'name' => 'Silver Hoop',
            'slug' => 'silver-hoop',
            'sku' => 'SH-1',
            'price' => 300,
            'purchase_price' => 100,
            'stock_quantity' => 2,
            'is_published' => true,
            'display_order' => 0,
        ]);

        $sorted = $this->get(route('shop', ['sort' => 'price_asc']));
        $sorted->assertOk();
        $sorted->assertSee('<meta name="robots" content="noindex, follow">', false);
        $sorted->assertSee('<link rel="canonical" href="'.route('shop').'">', false);

        $searched = $this->get(route('shop', ['q' => 'Silver']));
        $searched->assertOk();
        $searched->assertSee('Silver Hoop');
        $searched->assertSee('<meta name="robots" content="noindex, follow">', false);
        $searched->assertSee('<link rel="canonical" href="'.route('shop').'">', false);
    }

    #[Test]
    public function home_json_ld_search_action_targets_shop(): void
    {
        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertSee('"@type":"SearchAction"', false);
        $response->assertSee(route('shop').'?q={search_term_string}', false);
    }
}
