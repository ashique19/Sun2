<?php

namespace App\Livewire\Admin;

use App\Services\Ads\AdsLabConfigService;
use App\Support\AdminAccess;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Ads Settings')]
#[Layout('components.layouts.admin')]
class AdminAdsSettings extends Component
{
    public bool $productAfterDescription = true;

    public bool $productVideo = true;

    public string $productVideoSrc = '';

    public bool $popunder = true;

    public bool $exitInterstitial = true;

    public bool $labEnabled = true;

    public ?string $statusMessage = null;

    public ?string $error = null;

    public function mount(AdsLabConfigService $ads): void
    {
        AdminAccess::ensureStaffAdmin();
        $this->fillFromPlacements($ads->placements());
    }

    public function save(AdsLabConfigService $ads): void
    {
        AdminAccess::ensureStaffAdmin();
        $this->error = null;

        $this->validate([
            'productVideoSrc' => ['nullable', 'string', 'max:2000'],
        ]);

        $saved = $ads->savePlacements([
            'product_after_description' => $this->productAfterDescription,
            'product_video' => $this->productVideo,
            'product_video_src' => $this->productVideoSrc,
            'popunder' => $this->popunder,
            'exit_interstitial' => $this->exitInterstitial,
            'lab_enabled' => $this->labEnabled,
        ]);

        $this->fillFromPlacements($saved);
        $this->statusMessage = 'Ads settings saved.';
    }

    public function resetDefaults(AdsLabConfigService $ads): void
    {
        AdminAccess::ensureStaffAdmin();
        $this->error = null;
        $this->fillFromPlacements($ads->resetPlacementsToDefaults());
        $this->statusMessage = 'Restored defaults from config / .env.';
    }

    /**
     * @param  array{
     *     product_after_description: bool,
     *     product_video: bool,
     *     product_video_src: string,
     *     popunder: bool,
     *     exit_interstitial: bool,
     *     lab_enabled: bool
     * }  $placements
     */
    private function fillFromPlacements(array $placements): void
    {
        $this->productAfterDescription = $placements['product_after_description'];
        $this->productVideo = $placements['product_video'];
        $this->productVideoSrc = $placements['product_video_src'];
        $this->popunder = $placements['popunder'];
        $this->exitInterstitial = $placements['exit_interstitial'];
        $this->labEnabled = $placements['lab_enabled'];
    }

    public function render()
    {
        return view('livewire.admin.admin-ads-settings');
    }
}
