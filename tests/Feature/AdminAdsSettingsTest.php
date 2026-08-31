<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminAdsSettings;
use App\Models\Setting;
use App\Models\User;
use App\Services\Ads\AdsLabConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminAdsSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    #[Test]
    public function staff_can_open_ads_settings_page(): void
    {
        $this->actingAs($this->adminUser());

        $this->get(route('admin.ads'))
            ->assertOk()
            ->assertSee('Ads')
            ->assertSee('Product page video ad', false)
            ->assertSee('Popunder', false);
    }

    #[Test]
    public function staff_can_save_placement_toggles_to_settings(): void
    {
        $this->actingAs($this->adminUser());

        Livewire::test(AdminAdsSettings::class)
            ->set('productAfterDescription', false)
            ->set('productVideo', true)
            ->set('productVideoSrc', '//example.test/hilltop.js')
            ->set('popunder', false)
            ->set('exitInterstitial', true)
            ->set('labEnabled', false)
            ->call('save')
            ->assertSet('statusMessage', 'Ads settings saved.');

        $ads = app(AdsLabConfigService::class);
        $placements = $ads->placements();

        $this->assertFalse($placements['product_after_description']);
        $this->assertTrue($placements['product_video']);
        $this->assertSame('//example.test/hilltop.js', $placements['product_video_src']);
        $this->assertFalse($placements['popunder']);
        $this->assertTrue($placements['exit_interstitial']);
        $this->assertFalse($placements['lab_enabled']);
        $this->assertSame('//example.test/hilltop.js', $ads->productVideoAdSrc());
        $this->assertNull($ads->productAfterDescriptionLeaderboard());
        $this->assertFalse($ads->labEnabled());

        $raw = Setting::getValue(AdsLabConfigService::PLACEMENTS_SETTING_KEY);
        $this->assertNotNull($raw);
        $this->assertSame('ads', Setting::query()->where('key', AdsLabConfigService::PLACEMENTS_SETTING_KEY)->value('group'));
    }

    #[Test]
    public function reset_defaults_clears_stored_placements(): void
    {
        config([
            'ads.placements.product_video' => true,
            'ads.product_video_src' => '//from-env.test/video.js',
        ]);

        Setting::putValue(AdsLabConfigService::PLACEMENTS_SETTING_KEY, json_encode([
            'product_video' => false,
            'product_video_src' => '//saved.test/x.js',
        ]), 'ads');

        $this->actingAs($this->adminUser());

        Livewire::test(AdminAdsSettings::class)
            ->assertSet('productVideo', false)
            ->call('resetDefaults')
            ->assertSet('statusMessage', 'Restored defaults from config / .env.')
            ->assertSet('productVideo', true)
            ->assertSet('productVideoSrc', '//from-env.test/video.js');

        $this->assertNull(Setting::getValue(AdsLabConfigService::PLACEMENTS_SETTING_KEY));
    }

    #[Test]
    public function moderator_cannot_open_ads_settings(): void
    {
        Role::findOrCreate('moderator');
        $user = User::factory()->create();
        $user->assignRole('moderator');
        $this->actingAs($user);

        $this->get(route('admin.ads'))->assertForbidden();
    }
}
