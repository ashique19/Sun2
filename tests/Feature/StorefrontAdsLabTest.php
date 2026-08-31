<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StorefrontAdsLabTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function ads_lab_page_renders_with_hero_and_placeholders(): void
    {
        config(['ads.lab_enabled' => true]);
        config(['ads.adsterra.banner_728.key' => null]);

        $response = $this->get(route('ads.lab'));

        $response->assertOk();
        $response->assertSee('Sundoritoma', false);
        $response->assertSee(route('home'), false);
        $response->assertSee('728×90 Leaderboard', false);
        $response->assertSee('Placeholder', false);
        $response->assertSee('noindex, nofollow', false);
    }

    #[Test]
    public function ads_lab_page_is_not_found_when_disabled(): void
    {
        config(['ads.lab_enabled' => false]);

        $this->get(route('ads.lab'))->assertNotFound();
    }

    #[Test]
    public function ads_lab_renders_live_unit_script_when_slot_key_is_configured(): void
    {
        config(['ads.lab_enabled' => true]);
        config(['ads.adsterra.banner_300.key' => 'abc123slotkey000000000000000000']);

        $response = $this->get(route('ads.lab'));

        $response->assertOk();
        $response->assertSee('highperformancedformats.com/abc123slotkey000000000000000000/invoke.js', false);
        $response->assertSee('Live unit', false);
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
