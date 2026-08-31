<?php

namespace Tests\Feature;

use App\Services\Ads\AdsLabConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StorefrontPopunderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(AdsLabConfigService::class)->seedFromDefaultsIfMissing();
    }

    #[Test]
    public function home_page_includes_popunder_smartlink_when_enabled(): void
    {
        config(['ads.placements.popunder' => true]);

        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertSee('data-sun-popunder-smartlink', false);
        $response->assertSee('sun_ads_popunder_fired', false);
        $response->assertSee('xsjja7i0', false);
        $response->assertSee('7e680ac1f9ce8e5547eb972920f15f50', false);
    }

    #[Test]
    public function shop_page_includes_popunder_when_enabled(): void
    {
        config(['ads.placements.popunder' => true]);

        $response = $this->get(route('shop'));

        $response->assertOk();
        $response->assertSee('data-sun-popunder-smartlink', false);
    }

    #[Test]
    public function cart_page_excludes_popunder(): void
    {
        config(['ads.placements.popunder' => true]);

        $response = $this->get(route('cart'));

        $response->assertOk();
        $response->assertDontSee('data-sun-popunder-smartlink', false);
        $response->assertDontSee('sun_ads_popunder_fired', false);
    }

    #[Test]
    public function checkout_page_excludes_popunder(): void
    {
        config(['ads.placements.popunder' => true]);

        $response = $this->get(route('checkout'));

        // Checkout may redirect guests with an empty cart; still must not embed popunder.
        $response->assertDontSee('data-sun-popunder-smartlink', false);
        $response->assertDontSee('sun_ads_popunder_fired', false);
    }

    #[Test]
    public function login_page_excludes_popunder(): void
    {
        config(['ads.placements.popunder' => true]);

        $response = $this->get(route('login'));

        $response->assertOk();
        $response->assertDontSee('data-sun-popunder-smartlink', false);
    }

    #[Test]
    public function ads_lab_excludes_auto_popunder(): void
    {
        config([
            'ads.placements.popunder' => true,
            'ads.lab_enabled' => true,
        ]);

        $response = $this->get(route('ads.lab'));

        $response->assertOk();
        $response->assertDontSee('data-sun-popunder-smartlink', false);
    }

    #[Test]
    public function home_omits_popunder_when_disabled(): void
    {
        config(['ads.placements.popunder' => false]);

        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertDontSee('data-sun-popunder-smartlink', false);
    }

    #[Test]
    public function popunder_script_src_is_used_when_configured_instead_of_smartlink_handler(): void
    {
        config(['ads.placements.popunder' => true]);

        app(AdsLabConfigService::class)->save([
            'invoke_host' => 'www.highrevenueformat.com',
            'network' => 'adsterra',
            'banners' => [],
            'scripts' => [
                'popunder' => [
                    'label' => 'Pop-under script',
                    'type' => 'script_src',
                    'src' => 'https://pl.example.test/popunder.js',
                ],
            ],
        ]);

        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertSee('data-sun-popunder-script', false);
        $response->assertSee('https://pl.example.test/popunder.js', false);
        $response->assertDontSee('data-sun-popunder-smartlink', false);
    }
}
