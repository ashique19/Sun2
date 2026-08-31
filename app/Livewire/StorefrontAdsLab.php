<?php

namespace App\Livewire;

use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class StorefrontAdsLab extends Component
{
    public function mount(): void
    {
        abort_unless(config('ads.lab_enabled'), 404);
    }

    /**
     * @return list<array{key: string, label: string, description: string, slot_key: ?string, width: int, height: int, format: string}>
     */
    public function bannerSlots(): array
    {
        return collect(config('ads.adsterra', []))
            ->map(fn (array $slot, string $key): array => [
                'key' => $key,
                'label' => (string) ($slot['label'] ?? $key),
                'description' => (string) ($slot['description'] ?? ''),
                'slot_key' => filled($slot['key'] ?? null) ? (string) $slot['key'] : null,
                'width' => (int) ($slot['width'] ?? 300),
                'height' => (int) ($slot['height'] ?? 250),
                'format' => (string) ($slot['format'] ?? 'iframe'),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{key: string, label: string, description: string, body: ?string}>
     */
    public function scriptSlots(): array
    {
        return collect(config('ads.adsterra_scripts', []))
            ->map(fn (array $slot, string $key): array => [
                'key' => $key,
                'label' => (string) ($slot['label'] ?? $key),
                'description' => (string) ($slot['description'] ?? ''),
                'body' => filled($slot['body'] ?? null) ? (string) $slot['body'] : null,
            ])
            ->values()
            ->all();
    }

    public function render()
    {
        return view('livewire.storefront-ads-lab', [
            'bannerSlots' => $this->bannerSlots(),
            'scriptSlots' => $this->scriptSlots(),
            'network' => (string) config('ads.network', 'adsterra'),
        ])
            ->title(__('storefront.ads_lab_title').' - Sundoritoma')
            ->layoutData([
                'seoDescription' => __('storefront.ads_lab_meta_description'),
                'seoRobots' => 'noindex, nofollow',
            ]);
    }
}
