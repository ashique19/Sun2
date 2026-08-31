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
    public function seed_does_not_overwrite_existing_settings(): void
    {
        $service = app(AdsLabConfigService::class);
        $service->save([
            'network' => 'adsterra',
            'banners' => [
                'only' => [
                    'label' => 'Kept',
                    'type' => 'atoptions',
                    'key' => 'keep-me',
                    'width' => 100,
                    'height' => 100,
                ],
            ],
            'scripts' => [],
        ]);

        $service->seedFromDefaultsIfMissing();

        $this->assertCount(1, $service->units());
        $this->assertSame('Kept', $service->units()[0]['label']);
    }
}
