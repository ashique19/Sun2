<?php

namespace Tests\Feature;

use App\Services\Ads\AdsLabConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StorefrontAdsLabTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(AdsLabConfigService::class)->seedFromDefaultsIfMissing();
    }

    #[Test]
    public function ads_lab_page_renders_with_hero_and_configured_units(): void
    {
        config(['ads.lab_enabled' => true]);

        $response = $this->get(route('ads.lab'));

        $response->assertOk();
        $response->assertSee('Sundoritoma', false);
        $response->assertSee(route('home'), false);
        $response->assertSee('728×90 Leaderboard', false);
        $response->assertSee('300×250 Medium rectangle', false);
        $response->assertSee('160×600 Wide skyscraper', false);
        $response->assertSee('Native banner', false);
        $response->assertSee('Social bar', false);
        $response->assertSee('Live unit', false);
        $response->assertSee('noindex, nofollow', false);
        $response->assertSee('ads.lab.units', false);
    }

    #[Test]
    public function ads_lab_page_is_not_found_when_disabled(): void
    {
        config(['ads.lab_enabled' => false]);

        $this->get(route('ads.lab'))->assertNotFound();
    }

    #[Test]
    public function ads_lab_renders_units_from_database_settings(): void
    {
        config(['ads.lab_enabled' => true]);

        app(AdsLabConfigService::class)->save([
            'invoke_host' => 'www.highrevenueformat.com',
            'network' => 'adsterra',
            'banners' => [
                'banner_300' => [
                    'label' => '300×250 Medium rectangle',
                    'type' => 'atoptions',
                    'key' => 'a356eb5486bfece119efb08195fb4a25',
                    'width' => 300,
                    'height' => 250,
                    'format' => 'iframe',
                ],
            ],
            'scripts' => [
                'popunder' => [
                    'label' => 'Pop-under / smartlink',
                    'type' => 'smartlink',
                    'url' => 'https://www.profitableratecpmnetwork.com/xsjja7i0?key=7e680ac1f9ce8e5547eb972920f15f50',
                ],
            ],
        ]);

        $response = $this->get(route('ads.lab'));

        $response->assertOk();
        $response->assertSee(
            'https://www.highrevenueformat.com/a356eb5486bfece119efb08195fb4a25/invoke.js',
            false,
        );
        $response->assertSee(
            'profitableratecpmnetwork.com/xsjja7i0?key=7e680ac1f9ce8e5547eb972920f15f50',
            false,
        );
        $response->assertDontSee('728×90 Leaderboard', false);
    }

    #[Test]
    public function ads_lab_renders_native_and_script_defaults_from_seeded_settings(): void
    {
        config(['ads.lab_enabled' => true]);

        $response = $this->get(route('ads.lab'));

        $response->assertOk();
        $response->assertSee('container-0150ba9fc718f1f7f103b72a3757ae25', false);
        $response->assertSee(
            'pl31110128.profitableratecpmnetwork.com/0150ba9fc718f1f7f103b72a3757ae25/invoke.js',
            false,
        );
        $response->assertSee(
            'pl31110125.profitableratecpmnetwork.com/2e/28/d6/2e28d6b523d7ac452ad571b5139de0eb.js',
            false,
        );
    }

    #[Test]
    public function home_page_does_not_link_to_ads_lab(): void
    {
        config(['ads.lab_enabled' => true]);

        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertDontSee(route('ads.lab'), false);
    }
}
