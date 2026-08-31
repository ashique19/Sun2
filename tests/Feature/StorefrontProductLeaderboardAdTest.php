<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Services\Ads\AdsLabConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StorefrontProductLeaderboardAdTest extends TestCase
{
    use RefreshDatabase;

    private function publishedProduct(): Product
    {
        $category = Category::query()->create([
            'name' => 'Jewellery',
            'slug' => 'jewellery',
            'is_active' => true,
            'is_homepage' => true,
            'display_order' => 1,
        ]);

        return Product::query()->create([
            'category_id' => $category->id,
            'name' => 'Gold Ring',
            'slug' => 'gold-ring',
            'price' => 1500,
            'is_published' => true,
            'description' => '<p>Handmade ring description for placement test.</p>',
        ]);
    }

    #[Test]
    public function product_page_shows_728_leaderboard_after_description_when_enabled(): void
    {
        config(['ads.placements.product_after_description' => true]);
        app(AdsLabConfigService::class)->seedFromDefaultsIfMissing();

        $product = $this->publishedProduct();

        $response = $this->get(route('product.show', $product));

        $response->assertOk();
        $response->assertSee('Handmade ring description for placement test.', false);
        $response->assertSee('storefront-ad-banner', false);
        $response->assertSee('storefront-ad-banner__scroll', false);
        $response->assertSee('data-ad-key="6749cdd1ebf2dbcda3384c9f4c4f8cfb"', false);
        $response->assertSee('data-ad-key="2b562aa780f28739eee1965844207030"', false);
        $response->assertSee('www.highrevenueformat.com', false);
        $response->assertSee('width: 728', false);
        $response->assertSee('width: 320', false);
        $response->assertSee('md:hidden', false);
        $response->assertSee('hidden md:block', false);
        $response->assertDontSee('Live unit', false);
        $response->assertDontSee('728×90 Leaderboard', false);
    }

    #[Test]
    public function product_page_omits_leaderboard_when_placement_disabled(): void
    {
        config(['ads.placements.product_after_description' => false]);
        app(AdsLabConfigService::class)->seedFromDefaultsIfMissing();

        $product = $this->publishedProduct();

        $response = $this->get(route('product.show', $product));

        $response->assertOk();
        $response->assertDontSee('storefront-ad-banner', false);
        $response->assertDontSee('6749cdd1ebf2dbcda3384c9f4c4f8cfb', false);
    }

    #[Test]
    public function product_page_omits_leaderboard_when_banner_key_missing(): void
    {
        config(['ads.placements.product_after_description' => true]);

        app(AdsLabConfigService::class)->save([
            'invoke_host' => 'www.highrevenueformat.com',
            'network' => 'adsterra',
            'banners' => [
                'banner_728' => [
                    'label' => '728×90 Leaderboard',
                    'type' => 'atoptions',
                    'key' => null,
                    'width' => 728,
                    'height' => 90,
                    'format' => 'iframe',
                ],
            ],
            'scripts' => [],
        ]);

        $product = $this->publishedProduct();

        $response = $this->get(route('product.show', $product));

        $response->assertOk();
        $response->assertDontSee('storefront-ad-banner', false);
    }
}
