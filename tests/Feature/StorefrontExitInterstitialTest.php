<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Ads\AdsLabConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StorefrontExitInterstitialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(AdsLabConfigService::class)->seedFromDefaultsIfMissing();
        app(AdsLabConfigService::class)->mergeMissingDefaults();
    }

    #[Test]
    public function home_page_includes_exit_interstitial_when_enabled(): void
    {
        config(['ads.placements.exit_interstitial' => true]);

        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertSee('sun_exit_interstitial_shown', false);
        $response->assertSee('Securely closing in', false);
        $response->assertSee('CLOSE NOW', false);
        $response->assertSee('m75pp2jm', false);
        $response->assertSee('31573655d658b411102f48a4813350a7', false);
    }

    #[Test]
    public function cart_keeps_exit_interstitial_host_but_lists_cart_as_excluded_path(): void
    {
        config(['ads.placements.exit_interstitial' => true]);

        $response = $this->get(route('cart'));

        $response->assertOk();
        // Layout-mounted host persists across Livewire navigate; triggers gate on path.
        $response->assertSee('sun_exit_interstitial_shown', false);
        $response->assertSee('data-sun-exit-interstitial', false);
        $response->assertSee('/cart', false);
    }

    #[Test]
    public function home_omits_exit_interstitial_when_disabled(): void
    {
        config(['ads.placements.exit_interstitial' => false]);

        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertDontSee('sun_exit_interstitial_shown', false);
    }

    #[Test]
    public function merge_missing_defaults_adds_exit_smartlink_without_wiping_existing_units(): void
    {
        $service = app(AdsLabConfigService::class);

        $service->save([
            'network' => 'adsterra',
            'invoke_host' => 'www.highrevenueformat.com',
            'banners' => [
                'banner_300' => [
                    'label' => 'Keep me',
                    'type' => 'atoptions',
                    'key' => 'keep-300',
                    'width' => 300,
                    'height' => 250,
                ],
            ],
            'scripts' => [
                'popunder' => [
                    'label' => 'Pop',
                    'type' => 'smartlink',
                    'url' => 'https://example.test/pop',
                ],
            ],
        ]);

        $service->mergeMissingDefaults();

        $payload = json_decode((string) Setting::getValue(AdsLabConfigService::SETTING_KEY), true);

        $this->assertSame('keep-300', $payload['banners']['banner_300']['key']);
        $this->assertSame('https://example.test/pop', $payload['scripts']['popunder']['url']);
        $this->assertSame(
            'https://www.profitableratecpmnetwork.com/m75pp2jm?key=31573655d658b411102f48a4813350a7',
            $payload['scripts']['exit_smartlink']['url'],
        );
    }
}
