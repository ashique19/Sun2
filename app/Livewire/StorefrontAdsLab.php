<?php

namespace App\Livewire;

use App\Services\Ads\AdsLabConfigService;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class StorefrontAdsLab extends Component
{
    public function mount(): void
    {
        abort_unless(app(AdsLabConfigService::class)->labEnabled(), 404);
    }

    public function render(AdsLabConfigService $adsLab)
    {
        $units = $adsLab->units();

        return view('livewire.storefront-ads-lab', [
            'bannerUnits' => array_values(array_filter(
                $units,
                fn (array $u): bool => in_array($u['type'], ['atoptions', 'native_container'], true),
            )),
            'scriptUnits' => array_values(array_filter(
                $units,
                fn (array $u): bool => in_array($u['type'], ['script_src', 'smartlink'], true),
            )),
            'network' => $adsLab->network(),
            'invokeHost' => $adsLab->invokeHost(),
        ])
            ->title(__('storefront.ads_lab_title').' - Sundoritoma')
            ->layoutData([
                'seoDescription' => __('storefront.ads_lab_meta_description'),
                'seoRobots' => 'noindex, nofollow',
            ]);
    }
}
