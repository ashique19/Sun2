<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Ads\AdsLabConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdsLabConfigServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function falls_back_to_config_defaults_when_settings_row_is_missing(): void
    {
        $service = app(AdsLabConfigService::class);
        $service->clearStored();

        $payload = $service->payload();

        $this->assertSame('adsterra', $payload['network']);
        $this->assertArrayHasKey('banner_300', $payload['banners']);
        $this->assertSame(
            'a356eb5486bfece119efb08195fb4a25',
            $payload['banners']['banner_300']['key'],
        );
        $this->assertNull(Setting::getValue(AdsLabConfigService::SETTING_KEY));
    }

    #[Test]
    public function seed_from_defaults_persists_settings_row(): void
    {
        $service = app(AdsLabConfigService::class);

        $service->seedFromDefaultsIfMissing();

        $raw = Setting::getValue(AdsLabConfigService::SETTING_KEY);
        $this->assertNotNull($raw);

        $decoded = json_decode((string) $raw, true);
        $this->assertIsArray($decoded);
        $this->assertSame('ads', Setting::query()->where('key', AdsLabConfigService::SETTING_KEY)->value('group'));
        $this->assertSame(
            'a356eb5486bfece119efb08195fb4a25',
            $decoded['banners']['banner_300']['key'],
        );
    }

    #[Test]
    public function save_overrides_defaults_for_lab_units(): void
    {
        $service = app(AdsLabConfigService::class);

        $service->save([
            'invoke_host' => 'www.example-ads.test',
            'network' => 'adsterra',
            'banners' => [
                'banner_300' => [
                    'label' => 'Custom 300',
                    'type' => 'atoptions',
                    'key' => 'custom-key-300',
                    'width' => 300,
                    'height' => 250,
                    'format' => 'iframe',
                ],
            ],
            'scripts' => [],
        ]);

        $units = $service->units();

        $this->assertCount(1, $units);
        $this->assertSame('Custom 300', $units[0]['label']);
        $this->assertSame('custom-key-300', $units[0]['slot_key']);
        $this->assertSame('www.example-ads.test', $service->invokeHost());
    }

    #[Test]
    public function product_after_description_leaderboard_returns_banner_728_when_live(): void
    {
        config(['ads.placements.product_after_description' => true]);
        $service = app(AdsLabConfigService::class);
        $service->seedFromDefaultsIfMissing();

        $unit = $service->productAfterDescriptionLeaderboard();

        $this->assertNotNull($unit);
        $this->assertSame('banner_728', $unit['key']);
        $this->assertSame('6749cdd1ebf2dbcda3384c9f4c4f8cfb', $unit['slot_key']);
    }

    #[Test]
    public function product_after_description_leaderboard_is_null_when_placement_disabled(): void
    {
        config(['ads.placements.product_after_description' => false]);
        $service = app(AdsLabConfigService::class);
        $service->seedFromDefaultsIfMissing();

        $this->assertNull($service->productAfterDescriptionLeaderboard());
    }

    #[Test]
    public function storefront_popunder_returns_smartlink_when_enabled(): void
    {
        config(['ads.placements.popunder' => true]);
        $this->get(route('home'));

        $popunder = app(AdsLabConfigService::class)->storefrontPopunder();

        $this->assertNotNull($popunder);
        $this->assertStringContainsString('profitableratecpmnetwork.com/xsjja7i0', (string) $popunder['url']);
    }

    #[Test]
    public function storefront_popunder_is_null_on_excluded_checkout_route(): void
    {
        config(['ads.placements.popunder' => true]);
        $this->get(route('checkout'));

        $this->assertNull(app(AdsLabConfigService::class)->storefrontPopunder());
    }
}
