<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StorefrontAdsLabTest extends TestCase
{
    use RefreshDatabase;

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
    }

    #[Test]
    public function ads_lab_page_is_not_found_when_disabled(): void
    {
        config(['ads.lab_enabled' => false]);

        $this->get(route('ads.lab'))->assertNotFound();
    }

    #[Test]
    public function ads_lab_renders_highrevenueformat_invoke_scripts(): void
    {
        config(['ads.lab_enabled' => true]);
        config(['ads.invoke_host' => 'www.highrevenueformat.com']);
        config(['ads.banners.banner_300.key' => 'a356eb5486bfece119efb08195fb4a25']);

        $response = $this->get(route('ads.lab'));

        $response->assertOk();
        $response->assertSee(
            'https://www.highrevenueformat.com/a356eb5486bfece119efb08195fb4a25/invoke.js',
            false,
        );
        $response->assertSee(
            'https://www.highrevenueformat.com/6749cdd1ebf2dbcda3384c9f4c4f8cfb/invoke.js',
            false,
        );
    }

    #[Test]
    public function ads_lab_renders_native_container_and_script_units(): void
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
        $response->assertSee(
            'profitableratecpmnetwork.com/xsjja7i0?key=7e680ac1f9ce8e5547eb972920f15f50',
            false,
        );
    }

    #[Test]
    public function ads_lab_shows_placeholder_when_banner_key_cleared(): void
    {
        config(['ads.lab_enabled' => true]);
        config(['ads.banners.banner_728.key' => null]);

        $response = $this->get(route('ads.lab'));

        $response->assertOk();
        $response->assertSee('Placeholder', false);
        $response->assertDontSee(
            'https://www.highrevenueformat.com/6749cdd1ebf2dbcda3384c9f4c4f8cfb/invoke.js',
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
