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
     * @return list<array{
     *     key: string,
     *     label: string,
     *     description: string,
     *     type: string,
     *     slot_key: ?string,
     *     width: int,
     *     height: int,
     *     format: string,
     *     script_src: ?string,
     *     smartlink_url: ?string
     * }>
     */
    public function units(): array
    {
        $banners = collect(config('ads.banners', []))
            ->map(fn (array $slot, string $key): array => $this->normalizeUnit($key, $slot));

        $scripts = collect(config('ads.scripts', []))
            ->map(fn (array $slot, string $key): array => $this->normalizeUnit($key, $slot));

        return $banners->merge($scripts)->values()->all();
    }

    /**
     * @param  array<string, mixed>  $slot
     * @return array{
     *     key: string,
     *     label: string,
     *     description: string,
     *     type: string,
     *     slot_key: ?string,
     *     width: int,
     *     height: int,
     *     format: string,
     *     script_src: ?string,
     *     smartlink_url: ?string
     * }
     */
    private function normalizeUnit(string $key, array $slot): array
    {
        return [
            'key' => $key,
            'label' => (string) ($slot['label'] ?? $key),
            'description' => (string) ($slot['description'] ?? ''),
            'type' => (string) ($slot['type'] ?? 'atoptions'),
            'slot_key' => filled($slot['key'] ?? null) ? (string) $slot['key'] : null,
            'width' => (int) ($slot['width'] ?? 300),
            'height' => (int) ($slot['height'] ?? 250),
            'format' => (string) ($slot['format'] ?? 'iframe'),
            'script_src' => filled($slot['script_src'] ?? $slot['src'] ?? null)
                ? (string) ($slot['script_src'] ?? $slot['src'])
                : null,
            'smartlink_url' => filled($slot['url'] ?? null) ? (string) $slot['url'] : null,
        ];
    }

    public function render()
    {
        $units = $this->units();

        return view('livewire.storefront-ads-lab', [
            'bannerUnits' => array_values(array_filter(
                $units,
                fn (array $u): bool => in_array($u['type'], ['atoptions', 'native_container'], true),
            )),
            'scriptUnits' => array_values(array_filter(
                $units,
                fn (array $u): bool => in_array($u['type'], ['script_src', 'smartlink'], true),
            )),
            'network' => (string) config('ads.network', 'adsterra'),
        ])
            ->title(__('storefront.ads_lab_title').' - Sundoritoma')
            ->layoutData([
                'seoDescription' => __('storefront.ads_lab_meta_description'),
                'seoRobots' => 'noindex, nofollow',
            ]);
    }
}
